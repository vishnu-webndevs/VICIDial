<?php

namespace App\Services\Messaging;

use App\Models\MetaTemplateSyncLog;
use App\Models\MetaWhatsappTemplate;
use App\Models\ProviderAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MetaTemplateService
{
    public function syncTemplates(ProviderAccount $provider): array
    {
        $credentials = (array) ($provider->credentials_encrypted ?? []);
        $token = trim((string) ($credentials['meta_access_token'] ?? ''));
        $wabaId = trim((string) ($credentials['whatsapp_business_account_id'] ?? ''));

        if ($token === '' || $wabaId === '') {
            throw new \RuntimeException('Missing Meta WhatsApp access token or WhatsApp business account id.');
        }

        $syncLog = MetaTemplateSyncLog::query()->create([
            'tenant_id' => $provider->tenant_id,
            'provider_account_id' => $provider->id,
            'sync_started_at' => now(),
            'status' => 'running',
            'templates_fetched' => 0,
            'templates_synced' => 0,
            'templates_updated' => 0,
            'templates_failed' => 0,
            'raw_response' => null,
        ]);

        $result = [
            'templates_fetched' => 0,
            'templates_synced' => 0,
            'templates_updated' => 0,
            'templates_failed' => 0,
            'errors' => [],
        ];

        try {
            $response = Http::timeout(20)
                ->withToken($token)
                ->acceptJson()
                ->get("https://graph.facebook.com/v25.0/{$wabaId}/message_templates", [
                    'limit' => 100,
                ]);

            $syncLog->raw_response = $response->json();
            $syncLog->templates_fetched = 0;
            if (! $response->successful()) {
                $syncLog->status = 'failed';
                $syncLog->error_message = (string) ($response->json('error.message') ?? 'Failed to fetch Meta templates.');
                $syncLog->sync_completed_at = now();
                $syncLog->save();

                return [
                    'ok' => false,
                    'error' => $syncLog->error_message,
                    'status_code' => $response->status(),
                    'sync_log_id' => $syncLog->id,
                ];
            }

            $metaTemplates = $response->json('data', []);
            $result['templates_fetched'] = count($metaTemplates);
            $syncLog->templates_fetched = $result['templates_fetched'];

            foreach ($metaTemplates as $metaTemplate) {
                try {
                    $record = $this->writeTemplate($provider, $metaTemplate);
                    if ($record->wasRecentlyCreated) {
                        $result['templates_synced']++;
                    } else {
                        $result['templates_updated']++;
                    }
                } catch (Throwable $templateException) {
                    Log::error('Meta template sync failed for template.', [
                        'tenant_id' => $provider->tenant_id,
                        'provider_account_id' => $provider->id,
                        'meta_template_id' => $metaTemplate['id'] ?? null,
                        'error' => $templateException->getMessage(),
                    ]);
                    $result['templates_failed']++;
                    $result['errors'][] = [
                        'template_id' => $metaTemplate['id'] ?? null,
                        'error' => $templateException->getMessage(),
                    ];
                }
            }

            $syncLog->status = 'completed';
            $syncLog->templates_synced = $result['templates_synced'];
            $syncLog->templates_updated = $result['templates_updated'];
            $syncLog->templates_failed = $result['templates_failed'];
            $syncLog->sync_completed_at = now();
            $syncLog->save();

            return array_merge(['ok' => true, 'sync_log_id' => $syncLog->id], $result);
        } catch (Throwable $e) {
            Log::error('Meta template sync failed.', [
                'tenant_id' => $provider->tenant_id,
                'provider_account_id' => $provider->id,
                'error' => $e->getMessage(),
            ]);

            $syncLog->status = 'failed';
            $syncLog->error_message = $e->getMessage();
            $syncLog->sync_completed_at = now();
            $syncLog->save();

            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'sync_log_id' => $syncLog->id,
            ];
        }
    }

    private function writeTemplate(ProviderAccount $provider, array $metaTemplate): MetaWhatsappTemplate
    {
        $components = $this->normalizeComponents($metaTemplate['components'] ?? []);
        $status = trim((string) ($metaTemplate['status'] ?? '')) ?: 'PENDING_REVIEW';
        $language = trim((string) ($metaTemplate['language'] ?? 'en'));

        $record = MetaWhatsappTemplate::query()->updateOrCreate(
            [
                'tenant_id' => $provider->tenant_id,
                'meta_template_id' => (string) ($metaTemplate['id'] ?? Str::uuid()),
            ],
            [
                'provider_account_id' => $provider->id,
                'template_name' => trim((string) ($metaTemplate['name'] ?? '')),
                'category' => trim((string) ($metaTemplate['category'] ?? '')),
                'language' => $language,
                'status' => $status,
                'components' => $components,
                'has_header' => $this->hasComponentType($components, 'HEADER'),
                'has_body' => $this->hasComponentType($components, 'BODY'),
                'has_footer' => $this->hasComponentType($components, 'FOOTER'),
                'has_buttons' => $this->hasComponentType($components, 'BUTTON'),
                'button_count' => $this->countComponentType($components, 'BUTTON'),
                'variable_count' => $this->countTemplateVariables($components),
                'raw_payload' => $metaTemplate,
                'synced_at' => now(),
                'last_updated_at' => now(),
            ]
        );

        return $record;
    }

    private function normalizeComponents(array $components): array
    {
        return array_values(array_map(function ($component) {
            if (! is_array($component)) {
                return [];
            }
            return $component;
        }, $components));
    }

    private function hasComponentType(array $components, string $type): bool
    {
        foreach ($components as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === strtoupper($type)) {
                return true;
            }
        }

        return false;
    }

    private function countComponentType(array $components, string $type): int
    {
        $count = 0;
        foreach ($components as $component) {
            if (strtoupper((string) ($component['type'] ?? '')) === strtoupper($type)) {
                $count++;
            }
        }

        return $count;
    }

    private function countTemplateVariables(array $components): int
    {
        $variables = 0;
        foreach ($components as $component) {
            $text = trim((string) ($component['text'] ?? ''));
            $variables += preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $matches); // numeric placeholders
            if (isset($component['buttons']) && is_array($component['buttons'])) {
                foreach ($component['buttons'] as $button) {
                    $buttonText = trim((string) ($button['text'] ?? ''));
                    $variables += preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $buttonText, $buttonMatches);
                }
            }
        }

        return $variables;
    }

    public function buildTemplatePayload(MetaWhatsappTemplate $template, string $recipient, array $variables): array
    {
        $normalizedRecipient = preg_replace('/[^0-9]/', '', $recipient) ?: $recipient;
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $normalizedRecipient,
            'type' => 'template',
            'template' => [
                'name' => $template->template_name,
                'language' => [
                    'code' => $template->language,
                ],
                'components' => $this->buildTemplateComponents($template, $variables),
            ],
        ];

        return $payload;
    }

    private function buildTemplateComponents(MetaWhatsappTemplate $template, array $variables): array
    {
        $components = [];
        $parameters = $this->collectParameterValues($variables);

        foreach ((array) $template->components as $component) {
            $componentType = strtoupper((string) ($component['type'] ?? ''));
            if ($componentType === '') {
                continue;
            }

            $templateComponent = ['type' => strtolower($componentType)];

            if ($componentType === 'HEADER' && isset($component['format'])) {
                $templateComponent['format'] = strtolower((string) $component['format']);
            }

            if ($componentType === 'BUTTON' && isset($component['sub_type'])) {
                $templateComponent['sub_type'] = strtolower((string) $component['sub_type']);
            }

            $resolvedParameters = $this->resolveComponentParameters($component, $parameters);
            if (! empty($resolvedParameters)) {
                $templateComponent['parameters'] = $resolvedParameters;
            }

            $components[] = $templateComponent;
        }

        return $components;
    }

    private function resolveComponentParameters(array $component, array $parameters): array
    {
        $result = [];
        $text = trim((string) ($component['text'] ?? ''));
        $placeholders = $this->extractPlaceholderIndexes($text);

        foreach ($placeholders as $index) {
            $parameterValue = (string) ($parameters[$index] ?? $parameters[$index - 1] ?? '');
            $result[] = ['type' => 'text', 'text' => $parameterValue];
        }

        if ($result !== []) {
            return $result;
        }

        if ($text !== '' && preg_match('/\{\{\s*(\d+)\s*\}\}/', $text)) {
            return $result;
        }

        if ($component['type'] === 'BUTTON' && isset($component['text'])) {
            $buttonText = trim((string) $component['text']);
            if ($buttonText !== '') {
                return [['type' => 'text', 'text' => $buttonText]];
            }
        }

        return [];
    }

    private function extractPlaceholderIndexes(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $text, $matches);
        return array_map('intval', array_values(array_unique($matches[1] ?? [])));
    }

    private function collectParameterValues(array $variables): array
    {
        $values = [];

        $flattened = $this->flattenVariables($variables);
        foreach ($flattened as $key => $value) {
            if (is_numeric((string) $key) && trim((string) $key) !== '') {
                $values[(int) $key] = (string) $value;
            } else {
                $values[] = (string) $value;
            }
        }

        ksort($values);
        return array_values($values);
    }

    private function flattenVariables(array $variables): array
    {
        $flat = [];
        array_walk_recursive($variables, function ($value, $key) use (&$flat) {
            $flat[$key] = $value;
        });
        return $flat;
    }
}
