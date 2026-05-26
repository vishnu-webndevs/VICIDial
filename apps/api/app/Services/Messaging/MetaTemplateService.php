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
                ->get("https://graph.facebook.com/v20.0/{$wabaId}/message_templates", [
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
            
            if (isset($component['type']) && strtoupper($component['type']) === 'HEADER') {
                $format = strtoupper($component['format'] ?? '');
                if (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
                    $urlRaw = data_get($component, 'example.header_url');
                    $url = is_array($urlRaw) ? ($urlRaw[0] ?? null) : (is_string($urlRaw) ? $urlRaw : null);
                    
                    $handleRaw = data_get($component, 'example.header_handle');
                    $handleStr = is_array($handleRaw) ? ($handleRaw[0] ?? null) : (is_string($handleRaw) ? $handleRaw : null);
                    
                    // Sometimes the actual scontent URL is placed in header_handle by Meta.
                    $targetUrl = null;
                    if ($url && str_contains($url, 'scontent.whatsapp.net')) {
                        $targetUrl = $url;
                    } elseif ($handleStr && str_contains($handleStr, 'scontent.whatsapp.net')) {
                        $targetUrl = $handleStr;
                    }
                    
                    if ($targetUrl) {
                        try {
                            $response = \Illuminate\Support\Facades\Http::get($targetUrl);
                            if ($response->successful()) {
                                $ext = 'jpg';
                                if ($format === 'VIDEO') $ext = 'mp4';
                                if ($format === 'DOCUMENT') $ext = 'pdf';
                                
                                $path = parse_url($targetUrl, PHP_URL_PATH);
                                if ($path) {
                                    $pathExt = pathinfo($path, PATHINFO_EXTENSION);
                                    if ($pathExt) $ext = $pathExt;
                                }

                                $filename = 'meta_templates/' . md5($targetUrl) . '.' . $ext;
                                \Illuminate\Support\Facades\Storage::disk('public')->put($filename, $response->body());
                                
                                $localUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($filename);
                                
                                $component['example']['header_url'] = [$localUrl];
                                $component['example']['header_handle'] = [$localUrl];
                            }
                        } catch (\Throwable $e) {
                            \Illuminate\Support\Facades\Log::warning('Failed to download meta template image', ['url' => $targetUrl, 'error' => $e->getMessage()]);
                        }
                    }
                }
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

            $componentTypeLower = strtolower($componentType);
            if ($componentTypeLower === 'buttons') {
                $componentTypeLower = 'button';
            }

            $templateComponent = ['type' => $componentTypeLower];

            if ($componentTypeLower === 'buttons' || $componentTypeLower === 'button') {
                $templateComponent['type'] = 'button';
                $templateComponent['sub_type'] = strtolower((string) ($component['sub_type'] ?? 'quick_reply'));
                $templateComponent['index'] = (int) (count(array_filter($components, fn($c) => $c['type'] === 'button')));
            }

            $resolvedParameters = $this->resolveComponentParameters($component, $parameters);
            
            // Only include component if it has parameters
            if (!empty($resolvedParameters)) {
                $templateComponent['parameters'] = $resolvedParameters;
                $components[] = $templateComponent;
            }
        }

        return $components;
    }

    private function resolveComponentParameters(array $component, array $parameters): array
    {
        $result = [];
        $type = strtoupper((string) ($component['type'] ?? ''));
        $format = strtoupper((string) ($component['format'] ?? 'TEXT'));
        
        // For HEADER, the variable might be in 'text' or 'format' (if it contains placeholders)
        $text = (string) ($component['text'] ?? '');
        if ($text === '' && $type === 'HEADER') {
            $text = (string) ($component['format'] ?? '');
        }

        $placeholders = $this->extractPlaceholderIndexes($text);

        // Media headers usually don't have placeholders in 'text', 
        // but they REQUIRE exactly one parameter (the media link).
        if ($type === 'HEADER' && in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
            if (empty($placeholders)) {
                $placeholders = [1];
            }
        }

        foreach ($placeholders as $index) {
            $value = '';
            $possibleKeys = [];
            
            // For media headers, the uploaded campaign image/file has highest priority
            $isMediaHeader = $type === 'HEADER' && in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT']);
            
            if ($isMediaHeader) {
                $possibleKeys = [
                    'campaign_media_url',
                    "header_var_{$index}",
                    "var_{$index}",
                    $index,
                    (string) $index,
                ];
            } else {
                $possibleKeys = [
                    $index,
                    (string) $index,
                    "var_{$index}",
                    "header_var_{$index}",
                    "body_var_{$index}",
                    "button_var_{$index}",
                    // Common field fallbacks based on index (if not mapped)
                    $index === 1 ? 'first_name' : null,
                    $index === 1 ? 'full_name' : null,
                    $index === 2 ? 'company_name' : null,
                    $index === 2 ? 'company' : null,
                ];
                // Add all keys from parameters as possible keys if they are strings (only for non-media)
                $possibleKeys = array_merge($possibleKeys, array_keys($parameters));
            }
            
            $possibleKeys = array_filter(array_unique($possibleKeys));

            foreach ($possibleKeys as $key) {
                if (isset($parameters[$key]) && (string)$parameters[$key] !== '') {
                    $value = (string) $parameters[$key];
                    break;
                }
            }

            if ($value === '' && isset($parameters[$index - 1])) {
                $value = (string) $parameters[$index - 1];
            }

            if ($value === '' && $isMediaHeader) {
                $handleRaw = data_get($component, 'example.header_handle');
                $fallbackHandle = is_array($handleRaw) ? ($handleRaw[0] ?? null) : (is_string($handleRaw) ? $handleRaw : null);
                
                $urlRaw = data_get($component, 'example.header_url');
                $fallbackUrl = is_array($urlRaw) ? ($urlRaw[0] ?? null) : (is_string($urlRaw) ? $urlRaw : null);
                
                $fallbackValue = $fallbackHandle ?: $fallbackUrl;
                if ($fallbackValue) {
                    $value = $fallbackValue;
                }
            }

            $value = (string)$value;
            
            if ($type === 'HEADER') {
                if (str_starts_with(strtolower($value), 'http://')) {
                    $value = 'https://' . substr($value, 7);
                }
                $isUrl = str_starts_with(strtolower($value), 'http');
                // Ensure handles look like handles (e.g. 4/..., or base64) to avoid accidental text fallbacks
                $isHandle = ! $isUrl && $value !== '' && (str_contains($value, '/') || strlen($value) > 20);
                
                if ($format === 'IMAGE' && ($isUrl || $isHandle)) {
                    $mediaObj = $isUrl ? ['link' => $value] : ['handle' => $value];
                    $result[] = ['type' => 'image', 'image' => $mediaObj];
                    continue;
                }
                if ($format === 'VIDEO' && ($isUrl || $isHandle)) {
                    $mediaObj = $isUrl ? ['link' => $value] : ['handle' => $value];
                    $result[] = ['type' => 'video', 'video' => $mediaObj];
                    continue;
                }
                if ($format === 'DOCUMENT' && ($isUrl || $isHandle)) {
                    $mediaObj = $isUrl ? ['link' => $value] : ['handle' => $value];
                    if ($isUrl) {
                        $mediaObj['filename'] = 'Document';
                    }
                    $result[] = [
                        'type' => 'document',
                        'document' => $mediaObj,
                    ];
                    continue;
                }
                
                // If it's a media format but empty, skip it
                if (in_array($format, ['IMAGE', 'VIDEO', 'DOCUMENT'])) {
                    continue; 
                }
            }

            $result[] = ['type' => 'text', 'text' => $value];
        }

        return $result;
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
        return $this->flattenVariables($variables);
    }

    private function flattenVariables(array $variables): array
    {
        $flat = [];
        array_walk_recursive($variables, function ($value, $key) use (&$flat) {
            $flat[$key] = $value;
        });
        return $flat;
    }

    public function buildTemplateTextPreview(MetaWhatsappTemplate $template, array $variables): string
    {
        $parameters = $this->collectParameterValues($variables);
        $text = '';
        
        foreach ((array) $template->components as $component) {
            $type = strtoupper((string) ($component['type'] ?? ''));
            if ($type === 'HEADER' && isset($component['text'])) {
                $text .= $component['text'] . "\n\n";
            }
            if ($type === 'BODY' && isset($component['text'])) {
                $text .= $component['text'] . "\n\n";
            }
            if ($type === 'FOOTER' && isset($component['text'])) {
                $text .= $component['text'] . "\n\n";
            }
        }

        $text = trim($text);

        // Replace placeholders {{1}}, {{2}} etc.
        $text = preg_replace_callback('/\{\{\s*(\d+)\s*\}\}/', function ($matches) use ($parameters) {
            $index = (int) $matches[1];
            // Follow the same fallback logic as buildTemplateComponents
            $possibleKeys = [
                $index,
                (string) $index,
                "var_{$index}",
                "body_var_{$index}",
                $index === 1 ? 'first_name' : null,
                $index === 1 ? 'full_name' : null,
                $index === 2 ? 'company_name' : null,
                $index === 2 ? 'company' : null,
            ];
            
            $possibleKeys = array_merge($possibleKeys, array_keys($parameters));
            
            foreach ($possibleKeys as $key) {
                if ($key !== null && isset($parameters[$key]) && (string)$parameters[$key] !== '') {
                    return (string) $parameters[$key];
                }
            }
            
            if (isset($parameters[$index - 1])) {
                return (string) $parameters[$index - 1];
            }
            
            return $matches[0]; // leave it as {{1}} if no replacement found
        }, $text);

        return $text !== '' ? $text : "[Meta Template: {$template->template_name}]";
    }
}
