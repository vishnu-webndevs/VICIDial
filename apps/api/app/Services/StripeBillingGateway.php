<?php

namespace App\Services;

use App\Support\IntegrationMode;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

class StripeBillingGateway
{
    public function __construct(private readonly IntegrationMode $integrationMode)
    {
    }

    private string $baseUrl = 'https://api.stripe.com/v1';

    public function createCustomer(array $attributes): array
    {
        return $this->request('post', '/customers', $attributes);
    }

    public function createSetupIntent(string $customerId): array
    {
        return $this->request('post', '/setup_intents', [
            'customer' => $customerId,
            'usage' => 'off_session',
            'payment_method_types[]' => 'card',
        ]);
    }

    public function attachPaymentMethod(string $customerId, string $paymentMethodId): array
    {
        $attached = $this->request('post', "/payment_methods/{$paymentMethodId}/attach", [
            'customer' => $customerId,
        ]);

        $this->request('post', "/customers/{$customerId}", [
            'invoice_settings[default_payment_method]' => $paymentMethodId,
        ]);

        return $attached;
    }

    public function retrievePaymentMethod(string $paymentMethodId): array
    {
        return $this->request('get', "/payment_methods/{$paymentMethodId}");
    }

    public function createSubscription(string $customerId, string $priceId, array $metadata = []): array
    {
        $payload = [
            'customer' => $customerId,
            'items[0][price]' => $priceId,
            'proration_behavior' => 'create_prorations',
            'payment_behavior' => 'allow_incomplete',
            'expand[]' => 'latest_invoice.payment_intent',
        ];

        foreach ($metadata as $key => $value) {
            $payload["metadata[{$key}]"] = (string) $value;
        }

        return $this->request('post', '/subscriptions', $payload);
    }

    public function retrieveSubscription(string $subscriptionId): array
    {
        return $this->request('get', "/subscriptions/{$subscriptionId}", [
            'expand[]' => 'items.data.price',
        ]);
    }

    public function updateSubscriptionPrice(string $subscriptionId, string $subscriptionItemId, string $priceId): array
    {
        return $this->request('post', "/subscriptions/{$subscriptionId}", [
            'items[0][id]' => $subscriptionItemId,
            'items[0][price]' => $priceId,
            'proration_behavior' => 'create_prorations',
            'payment_behavior' => 'allow_incomplete',
            'expand[]' => 'latest_invoice.payment_intent',
        ]);
    }

    private function request(string $method, string $path, array $data = []): array
    {
        if ($this->integrationMode->isSandbox()) {
            return $this->sandboxRequest($method, $path, $data);
        }

        $secretKey = (string) config('services.stripe.secret_key', '');
        if ($secretKey === '') {
            throw new \RuntimeException('Stripe secret key is not configured.');
        }

        $url = $this->baseUrl.$path;

        try {
            $client = Http::asForm()
                ->acceptJson()
                ->withToken($secretKey);

            $response = strtolower($method) === 'get'
                ? $client->get($url, $data)
                : $client->send(strtoupper($method), $url, ['form_params' => $data]);

            $response->throw();

            return $response->json() ?? [];
        } catch (RequestException $e) {
            $body = $e->response?->json();
            $message = is_array($body) ? (string) data_get($body, 'error.message', 'Stripe API error') : $e->getMessage();

            throw new \RuntimeException("Stripe API request failed: {$message}", previous: $e);
        }
    }

    private function sandboxRequest(string $method, string $path, array $data): array
    {
        $base = $this->integrationMode->sandboxBaseUrl();
        $url = "{$base}/stripe/".ltrim($path, '/');
        $request = Http::acceptJson()->timeout(15);
        $response = strtolower($method) === 'get'
            ? $request->get($url, $data)
            : $request->send(strtoupper($method), $url, ['json' => $data]);

        if (! $response->ok()) {
            throw new \RuntimeException("Stripe sandbox request failed with status {$response->status()}.");
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
