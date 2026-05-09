<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\IntegrationMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SandboxThirdPartyController extends Controller
{
    public function __construct(private readonly IntegrationMode $integrationMode)
    {
    }

    public function handle(Request $request, string $service, ?string $path = null): JsonResponse
    {
        if ($this->integrationMode->isProduction()) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SANDBOX_DISABLED',
                    'message' => 'Sandbox endpoints are disabled in production mode.',
                ],
            ], 403);
        }

        $scenario = strtolower((string) ($request->header('x-sandbox-scenario')
            ?? $request->query('scenario')
            ?? $request->input('scenario', 'success')));

        $delayMs = max(0, min(10000, (int) $request->input('response_time_ms', (int) $request->query('response_time_ms', 0))));
        if ($delayMs > 0) {
            usleep($delayMs * 1000);
        }

        if ($failure = $this->buildFailureResponse($scenario)) {
            return $failure;
        }

        $normalizedService = strtolower($service);
        $normalizedPath = trim((string) $path, '/');

        return match ($normalizedService) {
            'stripe' => $this->handleStripe($request, $normalizedPath),
            'part3' => $this->handlePart3($normalizedPath),
            'provider' => $this->handleProvider($normalizedPath),
            default => response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SANDBOX_SERVICE_NOT_SUPPORTED',
                    'message' => "Unsupported sandbox service [{$normalizedService}].",
                ],
            ], 404),
        };
    }

    private function handleStripe(Request $request, string $path): JsonResponse
    {
        if ($path === 'customers' && $request->isMethod('post')) {
            return response()->json(['id' => 'cus_mock_'.Str::lower(Str::random(14))], 200);
        }

        if ($path === 'setup_intents' && $request->isMethod('post')) {
            $id = 'seti_mock_'.Str::lower(Str::random(14));

            return response()->json([
                'id' => $id,
                'client_secret' => "{$id}_secret_mock",
                'status' => 'requires_payment_method',
            ], 200);
        }

        if (preg_match('#^payment_methods/([^/]+)/attach$#', $path, $matches) === 1 && $request->isMethod('post')) {
            return response()->json([
                'id' => $matches[1],
                'object' => 'payment_method',
                'customer' => (string) $request->input('customer'),
            ], 200);
        }

        if (preg_match('#^payment_methods/([^/]+)$#', $path, $matches) === 1 && $request->isMethod('get')) {
            return response()->json([
                'id' => $matches[1],
                'card' => [
                    'brand' => 'visa',
                    'last4' => '4242',
                    'exp_month' => 12,
                    'exp_year' => 2030,
                ],
            ], 200);
        }

        if ($path === 'subscriptions' && $request->isMethod('post')) {
            return response()->json([
                'id' => 'sub_mock_'.Str::lower(Str::random(14)),
                'status' => 'active',
                'current_period_start' => now()->subHour()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [['id' => 'si_mock_'.Str::lower(Str::random(10))]],
                ],
            ], 200);
        }

        if (preg_match('#^subscriptions/([^/]+)$#', $path, $matches) === 1) {
            return response()->json([
                'id' => $matches[1],
                'status' => 'active',
                'current_period_start' => now()->subHour()->timestamp,
                'current_period_end' => now()->addMonth()->timestamp,
                'items' => [
                    'data' => [['id' => 'si_mock_existing']],
                ],
            ], 200);
        }

        return response()->json(['id' => 'mock_unknown'], 200);
    }

    private function handlePart3(string $path): JsonResponse
    {
        $operation = strtolower($path);

        $payload = match ($operation) {
            'messaging-sms-inbound' => ['mode' => 'sandbox', 'status' => 'received'],
            'messaging-sms-outbound' => ['mode' => 'sandbox', 'provider_message_id' => 'msg_mock_'.Str::lower(Str::random(14)), 'status' => 'sent'],
            'messaging-whatsapp-inbound' => ['mode' => 'sandbox', 'status' => 'received'],
            'messaging-whatsapp-outbound' => ['mode' => 'sandbox', 'provider_message_id' => 'wa_mock_'.Str::lower(Str::random(14)), 'status' => 'sent'],
            'teams-notify' => ['mode' => 'sandbox', 'delivered_at' => now()->toISOString()],
            'ai-handle' => ['mode' => 'sandbox', 'decision' => 'auto_route', 'confidence' => 0.86, 'recommended_route' => 'ring_group:default'],
            'graph-availability' => ['mode' => 'sandbox', 'slots' => [
                ['start' => now()->addDay()->setTime(9, 0)->toISOString(), 'end' => now()->addDay()->setTime(9, 30)->toISOString()],
            ]],
            'graph-booking' => ['mode' => 'sandbox', 'booking_id' => 'graph_mock_'.Str::lower(Str::random(12)), 'calendar_event_id' => 'evt_mock_'.Str::lower(Str::random(12)), 'confirmation_sent' => true],
            'workflow-run' => ['mode' => 'sandbox', 'run_id' => 'wf_mock_'.Str::lower(Str::random(12)), 'status' => 'completed', 'output' => ['steps_executed' => 3]],
            'reporting-unified' => ['mode' => 'sandbox', 'kpis' => ['voice_calls' => 120], 'ai' => ['average_confidence' => 0.82]],
            'governance-retention' => ['mode' => 'sandbox', 'policy_id' => 'ret_mock_'.Str::lower(Str::random(12)), 'effective_at' => now()->toISOString()],
            'governance-drill' => ['mode' => 'sandbox', 'drill_id' => 'drill_mock_'.Str::lower(Str::random(12)), 'status' => 'completed', 'rto_minutes' => 12, 'rpo_minutes' => 4],
            default => ['mode' => 'sandbox', 'status' => 'ok'],
        };

        return response()->json($payload, 200);
    }

    private function handleProvider(string $path): JsonResponse
    {
        if (preg_match('#^(twilio|vonage)/test-connection$#', $path, $matches) !== 1) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SANDBOX_PROVIDER_NOT_SUPPORTED',
                    'message' => "Unsupported provider sandbox path [{$path}].",
                ],
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'code' => null,
            'message' => null,
            'provider' => $matches[1],
            'mode' => 'sandbox',
        ], 200);
    }

    private function buildFailureResponse(string $scenario): ?JsonResponse
    {
        return match ($scenario) {
            'success' => null,
            'auth_failure' => response()->json([
                'success' => false,
                'error' => ['code' => 'AUTH_FAILURE', 'message' => 'Invalid authentication token.'],
            ], 401),
            'rate_limit' => response()->json([
                'success' => false,
                'error' => ['code' => 'RATE_LIMITED', 'message' => 'Too many requests.'],
            ], 429)->header('Retry-After', '30'),
            'invalid_data' => response()->json([
                'not_expected' => true,
                'details' => 'Malformed payload for consumer resilience testing.',
            ], 200),
            'network_timeout' => response()->json([
                'success' => false,
                'error' => ['code' => 'NETWORK_TIMEOUT', 'message' => 'Upstream timeout simulated by sandbox.'],
            ], 504),
            default => response()->json([
                'success' => false,
                'error' => ['code' => 'SANDBOX_SCENARIO_UNKNOWN', 'message' => "Unknown scenario [{$scenario}]."],
            ], 422),
        };
    }
}
