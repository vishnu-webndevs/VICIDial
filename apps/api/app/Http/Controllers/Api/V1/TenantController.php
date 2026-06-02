<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger)
    {
    }

    public function show(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        if (! $tenant) {
            return response()->json([
                'error' => [
                    'code' => 'TENANT_CONTEXT_REQUIRED',
                    'message' => 'No tenant context found for current user.',
                ],
            ], 403);
        }

        $tenant->loadMissing('settings');

        return response()->json([
            'data' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'settings' => $tenant->settings,
                'created_at' => $tenant->created_at?->toISOString(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        if (! $tenant) {
            return response()->json([
                'error' => [
                    'code' => 'TENANT_CONTEXT_REQUIRED',
                    'message' => 'No tenant context found for current user.',
                ],
            ], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'settings.timezone' => ['sometimes', 'timezone'],
            'settings.locale' => ['sometimes', 'string', 'max:10'],
            'settings.date_format' => ['sometimes', 'string', 'max:20'],
            'settings.branding_company_name' => ['nullable', 'string', 'max:255'],
            'settings.branding_logo_url' => ['nullable', 'url', 'max:500'],
            'settings.default_webhook_url' => ['nullable', 'url', 'max:500'],
            'settings.alert_email' => ['nullable', 'email', 'max:255'],
            'settings.default_caller_id' => ['nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
            'settings.voice_locale' => ['sometimes', 'string', 'max:20'],
            'settings.metadata' => ['sometimes', 'array'],
            'settings.metadata.default_lead_country' => ['sometimes', 'string', 'regex:/^[A-Z]{2}$/'],
            'settings.metadata.integration_mode' => ['sometimes', 'in:sandbox,production'],
            'settings.metadata.calling_window' => ['sometimes', 'array'],
            'settings.metadata.calling_window.days' => ['sometimes', 'array'],
            'settings.metadata.calling_window.days.*' => ['string', 'in:Mon,Tue,Wed,Thu,Fri,Sat,Sun'],
            'settings.metadata.calling_window.start_time' => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'settings.metadata.calling_window.end_time' => ['sometimes', 'string', 'regex:/^\d{2}:\d{2}$/'],
            'settings.metadata.calling_window.timezone' => ['sometimes', 'string'],
        ]);

        $oldTenant = $tenant->only(['name', 'slug', 'status']);
        if (isset($validated['name'])) {
            $tenant->name = $validated['name'];
            $tenant->save();
        }

        if (isset($validated['settings'])) {
            $settingsData = $validated['settings'];
            if (isset($settingsData['metadata'])) {
                $existingSettings = $tenant->settings()->first();
                $existingMetadata = $existingSettings ? (array) ($existingSettings->metadata ?? []) : [];
                $settingsData['metadata'] = array_merge($existingMetadata, $settingsData['metadata']);
            }
            $tenant->settings()->updateOrCreate(
                ['tenant_id' => $tenant->id],
                $settingsData,
            );
        }

        $tenant->refresh()->load('settings');
        $this->auditLogger->log(
            action: 'tenant.updated',
            resourceType: 'tenant',
            resourceId: $tenant->id,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            oldValues: $oldTenant,
            newValues: [
                'name' => $tenant->name,
                'settings' => $tenant->settings?->toArray(),
            ],
            request: $request
        );

        return response()->json([
            'data' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'status' => $tenant->status,
                'settings' => $tenant->settings,
                'created_at' => $tenant->created_at?->toISOString(),
            ],
        ]);
    }

    public function voiceProfile(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        if (! $tenant) {
            return response()->json([
                'error' => [
                    'code' => 'TENANT_CONTEXT_REQUIRED',
                    'message' => 'No tenant context found for current user.',
                ],
            ], 403);
        }

        $tenant->loadMissing('settings');
        $settings = $tenant->settings;

        return response()->json([
            'data' => [
                'default_caller_id' => $settings?->default_caller_id,
                'voice_locale' => $settings?->voice_locale ?? 'en-US',
            ],
        ]);
    }

    public function updateVoiceProfile(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        if (! $tenant) {
            return response()->json([
                'error' => [
                    'code' => 'TENANT_CONTEXT_REQUIRED',
                    'message' => 'No tenant context found for current user.',
                ],
            ], 403);
        }

        $validated = $request->validate([
            'default_caller_id' => ['nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
            'voice_locale' => ['required', 'string', 'max:20'],
        ]);

        $tenant->settings()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            $validated
        );

        $this->auditLogger->log(
            action: 'tenant.voice_profile_updated',
            resourceType: 'tenant_settings',
            resourceId: null,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            newValues: $validated,
            request: $request
        );

        return $this->voiceProfile($request);
    }

    public function recordingPolicy(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        if (! $tenant) {
            return response()->json([
                'error' => [
                    'code' => 'TENANT_CONTEXT_REQUIRED',
                    'message' => 'No tenant context found for current user.',
                ],
            ], 403);
        }

        $tenant->loadMissing('settings');
        $metadata = (array) ($tenant->settings?->metadata ?? []);
        $policy = (array) ($metadata['recording_policy'] ?? []);

        return response()->json([
            'data' => array_merge([
                'enabled' => false,
                'require_consent' => false,
                'consent_prompt' => 'This call may be recorded.',
                'destination_overrides' => [],
            ], $policy),
        ]);
    }

    public function updateRecordingPolicy(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        if (! $tenant) {
            return response()->json([
                'error' => [
                    'code' => 'TENANT_CONTEXT_REQUIRED',
                    'message' => 'No tenant context found for current user.',
                ],
            ], 403);
        }

        $validated = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'require_consent' => ['nullable', 'boolean'],
            'consent_prompt' => ['nullable', 'string', 'max:200'],
            'destination_overrides' => ['nullable', 'array', 'max:50'],
            'destination_overrides.*.pattern' => ['required_with:destination_overrides', 'string', 'max:120'],
            'destination_overrides.*.enabled' => ['nullable', 'boolean'],
            'destination_overrides.*.require_consent' => ['nullable', 'boolean'],
            'destination_overrides.*.consent_prompt' => ['nullable', 'string', 'max:200'],
        ]);

        $settings = $tenant->settings()->firstOrCreate(['tenant_id' => $tenant->id], ['metadata' => []]);
        $metadata = (array) ($settings->metadata ?? []);
        $policy = array_merge((array) ($metadata['recording_policy'] ?? []), $validated);
        $metadata['recording_policy'] = $policy;
        $settings->metadata = $metadata;
        $settings->save();

        $this->auditLogger->log(
            action: 'tenant.recording_policy_updated',
            resourceType: 'tenant_settings',
            resourceId: null,
            tenantId: $tenant->id,
            actorId: $request->user()?->id,
            newValues: $policy,
            request: $request
        );

        return $this->recordingPolicy($request);
    }
}
