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
        try {
            $tenantId = $request->header('X-Tenant-Id') ?? $request->user()->tenant_id;
            
            $providerAccountId = $request->query('provider_account_id');
            $providerQuery = ProviderAccount::query()
                ->where('tenant_id', $tenantId)
                ->where('provider_type', 'meta_whatsapp');

            if ($providerAccountId) {
                $providerQuery->where('id', $providerAccountId);
            }

            $provider = $providerQuery->latest('created_at')->first();
            
            if (! $provider) {
                return response()->json(['data' => ['templates' => []]], 200);
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
                'header_type' => $template->header_type,
                'header_content' => $template->header_content,
                'body' => $template->body,
                'footer' => $template->footer,
                'buttons' => is_string($template->buttons) ? json_decode($template->buttons, true) : $template->buttons,
                'rejection_reason' => $template->rejection_reason,
                'components' => is_string($template->components) ? json_decode($template->components, true) : $template->components,
                'synced_at' => $template->synced_at?->toISOString(),
            ];
        });

        return response()->json(['data' => ['templates' => $templates]], 200);
        } catch (\Throwable $e) {
            Log::error('Listing meta templates failed.', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => [
                    'code' => 'FETCH_FAILED',
                    'message' => 'Unable to fetch meta templates. Make sure migrations are run.',
                ],
                'details' => ['error' => $e->getMessage()]
            ], 500);
        }
    }

    public function store(Request $request, MetaTemplateService $service): JsonResponse
    {
        try {
            $tenantId = $request->header('X-Tenant-Id') ?? $request->user()->tenant_id;
            
            $validated = $request->validate([
                'name' => ['required', 'string', 'regex:/^[a-z0-9_]+$/'],
                'category' => ['required', 'string', 'in:MARKETING,UTILITY,AUTHENTICATION'],
                'language' => ['required', 'string'],
                'header_type' => ['nullable', 'string', 'in:NONE,TEXT,IMAGE,VIDEO,DOCUMENT'],
                'header_content' => ['nullable', 'string'],
                'header_file' => ['nullable', 'file', 'max:10240'], // max 10MB
                'body' => ['required', 'string'],
                'footer' => ['nullable', 'string'],
                'buttons' => ['nullable'], // can be string (JSON) or array depending on FormData parsing
                'provider_account_id' => ['nullable', 'string'],
            ]);

            if ($request->hasFile('header_file')) {
                $file = $request->file('header_file');
                $path = $file->store('meta_templates', 'public');
                $validated['header_content'] = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
            }

            if (is_string($validated['buttons'] ?? null)) {
                $validated['buttons'] = json_decode($validated['buttons'], true);
            }

            $providerQuery = ProviderAccount::query()
                ->where('tenant_id', $tenantId)
                ->where('provider_type', 'meta_whatsapp');

            if (!empty($validated['provider_account_id'])) {
                $providerQuery->where('id', $validated['provider_account_id']);
            }

            $provider = $providerQuery->latest('created_at')->first();
            if (!$provider) {
                return response()->json([
                    'error' => [
                        'code' => 'PROVIDER_NOT_FOUND',
                        'message' => 'Meta WhatsApp provider account not found.',
                    ],
                ], 404);
            }

            $validated['created_by'] = $request->user()->id;

            $result = $service->createTemplate($provider, $validated);

            if (! ($result['ok'] ?? false)) {
                return response()->json([
                    'error' => [
                        'code' => 'CREATE_FAILED',
                        'message' => $result['error'] ?? 'Unable to create meta template.',
                    ],
                    'details' => $result,
                ], 422);
            }

            return response()->json(['data' => ['template' => $result['template']]], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'error' => [
                    'code' => 'VALIDATION_ERROR',
                    'message' => 'Invalid template data.',
                ],
                'details' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Meta template creation failed.', ['error' => $e->getMessage()]);
            return response()->json([
                'error' => [
                    'code' => 'CREATE_EXCEPTION',
                    'message' => 'Unable to create meta template.',
                ],
                'details' => ['error' => $e->getMessage()],
            ], 500);
        }
    }

    public function sync(Request $request, MetaTemplateService $service): JsonResponse
    {
        try {
            $tenantId = $request->header('X-Tenant-Id') ?? $request->user()->tenant_id;

            $validated = $request->validate([
                'provider_account_id' => ['nullable', 'string'],
            ]);

            $providerQuery = ProviderAccount::query()
                ->where('tenant_id', $tenantId)
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
            Log::error('Meta template sync failed.', ['error' => $e->getMessage()]);
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
