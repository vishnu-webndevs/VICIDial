<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProviderAccount;
use App\Services\Messaging\MetaTemplateService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class MetaTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tenant = $request->user()->currentTenant();

        $providerAccountId = $request->query('provider_account_id');
        $providerQuery = ProviderAccount::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider_type', 'meta_whatsapp');

        if ($providerAccountId) {
            $providerQuery->where('id', $providerAccountId);
        }

        $provider = $providerQuery->latest('created_at')->first();
        if (! $provider) {
            return response()->json([
                'error' => [
                    'code' => 'PROVIDER_NOT_FOUND',
                    'message' => 'Meta WhatsApp provider account not found.',
                ],
            ], 404);
        }

        $templates = $provider->whatsAppTemplates()->get()->map(function ($template) {
            return [
                'id' => $template->id,
                'meta_template_id' => $template->meta_template_id,
                'template_name' => $template->template_name,
                'language' => $template->language,
                'status' => $template->status,
                'category' => $template->category,
                'button_count' => $template->button_count,
                'variable_count' => $template->variable_count,
                'has_header' => $template->has_header,
                'has_body' => $template->has_body,
                'has_footer' => $template->has_footer,
                'synced_at' => $template->synced_at?->toISOString(),
            ];
        });

        return response()->json(['data' => ['templates' => $templates]], 200);
    }

    public function sync(Request $request, MetaTemplateService $service): JsonResponse
    {
        $tenant = $request->user()->currentTenant();

        $validated = $request->validate([
            'provider_account_id' => ['nullable', 'string'],
        ]);

        $providerQuery = ProviderAccount::query()
            ->where('tenant_id', $tenant->id)
            ->where('provider_type', 'meta_whatsapp');

        if (! empty($validated['provider_account_id'])) {
            $providerQuery->where('id', $validated['provider_account_id']);
        }

        $provider = $providerQuery->latest('created_at')->first();
        if (! $provider) {
            return response()->json([
                'error' => [
                    'code' => 'PROVIDER_NOT_FOUND',
                    'message' => 'Meta WhatsApp provider account not found.',
                ],
            ], 404);
        }

        try {
            $result = $service->syncTemplates($provider);

            if (! ($result['ok'] ?? false)) {
                return response()->json([
                    'error' => [
                        'code' => 'SYNC_FAILED',
                        'message' => $result['error'] ?? 'Unable to sync meta templates.',
                    ],
                    'details' => $result,
                ], 422);
            }

            return response()->json(['data' => ['sync' => $result]], 200);
        } catch (\Throwable $e) {
            Log::error('Meta template sync failed.', ['error' => $e->getMessage(), 'tenant_id' => $tenant->id]);
            return response()->json([
                'error' => [
                    'code' => 'SYNC_EXCEPTION',
                    'message' => 'Unable to sync meta templates.',
                ],
                'details' => ['error' => $e->getMessage()],
            ], 500);
        }
    }
}
