<?php

namespace App\Services\Messaging;

use Illuminate\Support\Arr;

class MessageTemplateRenderer
{
    public function render(string $template, array $variables): string
    {
        $flat = Arr::dot($variables);

        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.-]+)\s*\}\}/',
            fn (array $matches) => (string) ($flat[$matches[1]] ?? ''),
            $template
        );
    }
}

