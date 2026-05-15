<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\MetaWhatsappTemplate;
use App\Services\Messaging\MetaTemplateService;
use App\Models\Lead;

// 1. Get a sample Meta Template
$template = MetaWhatsappTemplate::first();
if (!$template) {
    echo "No Meta template found in DB. Please sync templates first.\n";
    exit;
}

// 2. Create a dummy Lead
$lead = new Lead([
    'full_name' => 'John Doe',
    'phone' => '919876543210',
    'company' => 'ACME Corp'
]);

// 3. Prepare variables (Simulating Job logic)
$fullName = trim((string) ($lead->full_name ?? ''));
$parts = preg_split('/\s+/', $fullName) ?: [];
$firstName = (string) ($parts[0] ?? '');
$lastName = count($parts) > 1 ? (string) end($parts) : '';

$variables = [
    'first_name' => $firstName,
    'last_name' => $lastName,
    'company_name' => (string) ($lead->company ?? ''),
    'phone' => (string) ($lead->phone ?? ''),
    'email' => (string) ($lead->email ?? ''),
];

// 4. Build Payload
$service = app(MetaTemplateService::class);
$payload = $service->buildTemplatePayload($template, $lead->phone, $variables);

echo "--- TEMPLATE NAME: " . $template->template_name . " ---\n";
echo "--- GENERATED PAYLOAD ---\n";
echo json_encode($payload, JSON_PRETTY_PRINT) . "\n";
echo "-------------------------\n";
