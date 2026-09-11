<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiBotAgent;
use App\Models\AiBotInteractiveFlow;
use App\Models\TenantAiSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiBotController extends Controller
{
    /**
     * List AI Bot Agents for tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $agents = AiBotAgent::query()
            ->where('tenant_id', $tenant->id)
            ->with('interactiveFlows')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $agents,
        ]);
    }

    /**
     * Create a new AI Bot Agent.
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'system_instructions' => ['nullable', 'string', 'max:5000'],
            'privacy_policy' => ['nullable', 'string', 'max:2000'],
            'knowledge_base' => ['nullable', 'array'],
            'custom_knowledge_prompt' => ['nullable', 'string', 'max:10000'],
            'fallback_message' => ['nullable', 'string', 'max:500'],
            'strict_mode' => ['nullable', 'boolean'],
            'human_delay_seconds' => ['nullable', 'integer', 'min:1', 'max:10'],
            'is_active' => ['nullable', 'boolean'],
            'interactive_flows' => ['nullable', 'array'],
        ]);

        $agent = AiBotAgent::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'system_instructions' => $validated['system_instructions'] ?? null,
            'privacy_policy' => $validated['privacy_policy'] ?? null,
            'knowledge_base' => $validated['knowledge_base'] ?? [],
            'custom_knowledge_prompt' => $validated['custom_knowledge_prompt'] ?? null,
            'fallback_message' => $validated['fallback_message'] ?? 'Mujhe iski exact jankari abhi nahi hai, main confirm karke aapko bataunga.',
            'strict_mode' => $validated['strict_mode'] ?? true,
            'human_delay_seconds' => $validated['human_delay_seconds'] ?? 3,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (!empty($validated['interactive_flows']) && is_array($validated['interactive_flows'])) {
            foreach ($validated['interactive_flows'] as $flow) {
                if (!empty($flow['trigger_keyword']) && !empty($flow['question_text'])) {
                    AiBotInteractiveFlow::create([
                        'ai_bot_agent_id' => $agent->id,
                        'trigger_keyword' => $flow['trigger_keyword'],
                        'response_type' => $flow['response_type'] ?? 'button_list',
                        'question_text' => $flow['question_text'],
                        'options' => $flow['options'] ?? [],
                    ]);
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $agent->load('interactiveFlows'),
        ], 201);
    }

    /**
     * Show single AI Bot Agent.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $agent = AiBotAgent::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->with('interactiveFlows')
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $agent,
        ]);
    }

    /**
     * Update AI Bot Agent.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $agent = AiBotAgent::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'system_instructions' => ['nullable', 'string', 'max:5000'],
            'privacy_policy' => ['nullable', 'string', 'max:2000'],
            'knowledge_base' => ['nullable', 'array'],
            'custom_knowledge_prompt' => ['nullable', 'string', 'max:10000'],
            'fallback_message' => ['nullable', 'string', 'max:500'],
            'strict_mode' => ['nullable', 'boolean'],
            'human_delay_seconds' => ['nullable', 'integer', 'min:1', 'max:10'],
            'is_active' => ['nullable', 'boolean'],
            'interactive_flows' => ['nullable', 'array'],
        ]);

        $agent->update($validated);

        if (isset($validated['interactive_flows']) && is_array($validated['interactive_flows'])) {
            // Re-sync interactive flows
            AiBotInteractiveFlow::where('ai_bot_agent_id', $agent->id)->delete();
            foreach ($validated['interactive_flows'] as $flow) {
                if (!empty($flow['trigger_keyword']) && !empty($flow['question_text'])) {
                    AiBotInteractiveFlow::create([
                        'ai_bot_agent_id' => $agent->id,
                        'trigger_keyword' => $flow['trigger_keyword'],
                        'response_type' => $flow['response_type'] ?? 'button_list',
                        'question_text' => $flow['question_text'],
                        'options' => $flow['options'] ?? [],
                    ]);
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => $agent->load('interactiveFlows'),
        ]);
    }

    /**
     * Delete AI Bot Agent.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $agent = AiBotAgent::query()
            ->where('tenant_id', $tenant->id)
            ->where('id', $id)
            ->firstOrFail();

        $agent->delete();

        return response()->json([
            'success' => true,
            'message' => 'AI Bot Agent deleted successfully.',
        ]);
    }

    /**
     * Get Tenant AI Settings (API Keys).
     */
    public function getAiSettings(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $settings = TenantAiSetting::query()
            ->where('tenant_id', $tenant->id)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'provider' => $settings?->provider ?? 'gemini',
                'enabled' => $settings?->enabled ?? true,
                'has_api_key' => !empty($settings?->api_key),
                'default_model' => $settings?->default_model ?? 'gemini-flash-latest',
            ],
        ]);
    }

    /**
     * Save Tenant AI Settings.
     */
    public function saveAiSettings(Request $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        $validated = $request->validate([
            'provider' => ['nullable', 'string', 'max:30'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
            'default_model' => ['nullable', 'string', 'max:50'],
        ]);

        $dataToUpdate = [
            'provider' => $validated['provider'] ?? 'gemini',
            'enabled' => $validated['enabled'] ?? true,
            'default_model' => $validated['default_model'] ?? (($validated['provider'] ?? '') === 'openai' ? 'gpt-4o-mini' : 'gemini-flash-latest'),
        ];

        if (!empty($validated['api_key'])) {
            $dataToUpdate['api_key'] = $validated['api_key'];
        }

        $settings = TenantAiSetting::updateOrCreate(
            ['tenant_id' => $tenant->id],
            $dataToUpdate
        );

        return response()->json([
            'success' => true,
            'message' => 'AI Settings saved successfully.',
        ]);
    }
}
