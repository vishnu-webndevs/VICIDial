<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CallEvent;
use App\Models\CallLeg;
use App\Models\CallSession;
use App\Models\ContactPhone;
use App\Models\ProviderAccount;
use App\Models\RingGroup;
use App\Services\Providers\ProviderAdapterManager;
use App\Services\UsageQuotaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderWebhookController extends Controller
{
    public function __construct(
        private readonly ProviderAdapterManager $adapterManager,
        private readonly UsageQuotaService $usageQuotaService,
    ) {
    }

    public function twilio(Request $request): JsonResponse
    {
        return $this->handle($request, 'twilio');
    }

    public function vonage(Request $request): JsonResponse
    {
        return $this->handle($request, 'vonage');
    }

    private function handle(Request $request, string $providerType): JsonResponse
    {
        $payload = $request->all();
        $provider = $this->resolveProvider($providerType, $payload);
        if (! $provider) {
            return response()->json(['message' => 'Provider account not found.'], 404);
        }

        $adapter = $this->adapterManager->for($providerType);

        // Skip signature verification in local/development environments so that
        // webhooks delivered through tunnels (ngrok, etc.) are never silently dropped
        // due to URL mismatches in the HMAC payload.
        $isLocal = in_array(config('app.env'), ['local', 'development'], true);
        if (! $isLocal) {
            $rawPayload = $request->getContent();
            $headers = $request->headers->all();
            if (! $adapter->verifyWebhookSignature($rawPayload, $headers, $payload, (array) $provider->credentials_encrypted)) {
                return response()->json(['message' => 'Invalid webhook signature.'], 400);
            }
        }

        $normalized = $adapter->normalizeWebhookEvent($payload);
        $call = CallSession::query()
            ->where('tenant_id', $provider->tenant_id)
            ->where('provider_account_id', $provider->id)
            ->where('provider_call_id', $normalized['provider_call_id'])
            ->first();

        if (! $call) {
            $from = (string) ($payload['From'] ?? $payload['from'] ?? '');
            $to = (string) ($payload['To'] ?? $payload['to'] ?? '');
            $contactPhone = ContactPhone::query()
                ->where('tenant_id', $provider->tenant_id)
                ->where('e164', $from)
                ->first();
            $ringGroup = RingGroup::query()
                ->where('tenant_id', $provider->tenant_id)
                ->where('active', true)
                ->orderBy('created_at')
                ->first();

            $call = CallSession::query()->create([
                'tenant_id' => $provider->tenant_id,
                'provider_account_id' => $provider->id,
                'contact_id' => $contactPhone?->contact_id,
                'direction' => 'inbound',
                'status' => 'ringing',
                'runtime_state' => 'ivr_greeting',
                'provider_call_id' => (string) $normalized['provider_call_id'],
                'from_number' => $from ?: null,
                'to_number' => $to ?: 'unknown',
                'routed_to' => $ringGroup ? 'ring_group:'.$ringGroup->id : null,
                'routing_confidence' => $ringGroup ? 1.0 : 0.2,
                'metadata' => ['webhook_source' => $providerType, 'auto_created' => true],
                'started_at' => now(),
            ]);

            CallLeg::query()->create([
                'tenant_id' => $call->tenant_id,
                'call_session_id' => $call->id,
                'from_number' => $call->from_number,
                'to_number' => $call->to_number,
                'status' => 'ringing',
                'started_at' => now(),
                'metadata' => ['provider' => $providerType, 'initial_leg' => true],
            ]);
        }

        $call->status = (string) $normalized['status'];
        $call->runtime_state = $this->mapRuntimeState($call->status);
        if (! is_null($normalized['duration_seconds'])) {
            $call->duration_seconds = (int) $normalized['duration_seconds'];
        }
        if (in_array($call->status, ['in_progress', 'answered'], true) && ! $call->started_at) {
            $call->started_at = now();
        }
        if (in_array($call->status, ['completed', 'failed', 'busy', 'no_answer', 'timeout', 'rejected', 'canceled'], true)) {
            $call->ended_at = now();
            if ($call->status !== 'completed') {
                $call->failure_reason = (string) ($normalized['provider_event_type'] ?? 'provider_failure');
            }
        }
        $call->save();

        CallEvent::query()->create([
            'tenant_id' => $call->tenant_id,
            'call_session_id' => $call->id,
            'provider_account_id' => $provider->id,
            'event_type' => (string) $normalized['event_type'],
            'provider_event_type' => (string) $normalized['provider_event_type'],
            'status_after' => $call->status,
            'payload' => $payload,
            'occurred_at' => $normalized['occurred_at'],
        ]);

        $this->usageQuotaService->consume($provider->tenant_id, 'webhook_events', 1, 'provider_webhook', $provider->id);
        if ($call->status === 'completed' && ! is_null($call->duration_seconds) && $call->duration_seconds > 0) {
            $minutes = (int) ceil($call->duration_seconds / 60);
            $this->usageQuotaService->consume($provider->tenant_id, 'call_minutes', $minutes, 'call_session', $call->id);
        }

        return response()->json(['received' => true], 200);
    }

    private function mapRuntimeState(string $status): string
    {
        return match ($status) {
            'queued', 'ringing' => 'ringing',
            'answered', 'in_progress' => 'connected',
            'completed' => 'completed',
            'busy', 'no_answer', 'timeout' => 'missed',
            'failed', 'rejected', 'canceled' => 'failed',
            default => 'initiated',
        };
    }

    private function resolveProvider(string $providerType, array $payload): ?ProviderAccount
    {
        $providers = ProviderAccount::query()
            ->where('provider_type', $providerType)
            ->where('status', 'active')
            ->get();

        foreach ($providers as $provider) {
            $credentials = (array) $provider->credentials_encrypted;
            if (
                $providerType === 'twilio'
                && isset($payload['AccountSid'])
                && ($credentials['account_sid'] ?? null) === $payload['AccountSid']
            ) {
                return $provider;
            }
            if (
                $providerType === 'vonage'
                && isset($payload['api_key'])
                && (string) ($credentials['api_key'] ?? '') === (string) $payload['api_key']
            ) {
                return $provider;
            }
        }

        return null;
    }
}
