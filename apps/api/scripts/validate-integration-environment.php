<?php

declare(strict_types=1);

$appEnv = strtolower((string) getenv('APP_ENV'));
$integrationMode = strtolower((string) getenv('INTEGRATION_MODE'));
$integrationMode = $integrationMode !== '' ? $integrationMode : ($appEnv === 'production' ? 'production' : 'sandbox');

$stripeSecret = (string) getenv('STRIPE_SECRET_KEY');
$part3Urls = array_filter([
    getenv('PART3_SMS_INBOUND_URL') ?: '',
    getenv('PART3_SMS_OUTBOUND_URL') ?: '',
    getenv('PART3_WHATSAPP_INBOUND_URL') ?: '',
    getenv('PART3_WHATSAPP_OUTBOUND_URL') ?: '',
    getenv('PART3_TEAMS_URL') ?: '',
    getenv('PART3_AI_URL') ?: '',
    getenv('PART3_GRAPH_AVAILABILITY_URL') ?: '',
    getenv('PART3_GRAPH_BOOKING_URL') ?: '',
    getenv('PART3_WORKFLOW_URL') ?: '',
    getenv('PART3_REPORTING_URL') ?: '',
    getenv('PART3_GOVERNANCE_RETENTION_URL') ?: '',
    getenv('PART3_GOVERNANCE_DRILL_URL') ?: '',
], fn (string $value) => $value !== '');

$looksSandbox = static function (string $value): bool {
    $value = strtolower($value);

    return str_contains($value, 'localhost')
        || str_contains($value, '127.0.0.1')
        || str_contains($value, '/sandbox/')
        || str_contains($value, '/mock/');
};

$errors = [];

if ($integrationMode === 'production') {
    if ($stripeSecret === '' || str_starts_with($stripeSecret, 'sk_test_')) {
        $errors[] = 'Production integration mode requires STRIPE_SECRET_KEY with sk_live_.';
    }

    foreach ($part3Urls as $url) {
        if ($looksSandbox($url)) {
            $errors[] = "Production integration mode cannot use sandbox Part3 URL: {$url}";
        }
    }
}

if ($integrationMode === 'sandbox') {
    if (str_starts_with($stripeSecret, 'sk_live_')) {
        $errors[] = 'Sandbox integration mode cannot use live Stripe keys (sk_live_).';
    }
}

if ($integrationMode !== 'sandbox' && $integrationMode !== 'production') {
    $errors[] = "INTEGRATION_MODE must resolve to sandbox or production, got [{$integrationMode}].";
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "[integration-env] {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "[integration-env] validation passed for mode={$integrationMode}, app_env={$appEnv}\n");
