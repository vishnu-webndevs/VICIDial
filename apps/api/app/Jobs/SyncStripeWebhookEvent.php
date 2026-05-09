<?php

namespace App\Jobs;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\StripeWebhookEvent;
use App\Models\Subscription;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SyncStripeWebhookEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $webhookEventId)
    {
    }

    public function handle(): void
    {
        $webhook = StripeWebhookEvent::query()->findOrFail($this->webhookEventId);
        if ($webhook->status === 'processed') {
            return;
        }

        try {
            $type = (string) data_get($webhook->payload, 'type', '');
            $object = data_get($webhook->payload, 'data.object', []);

            DB::transaction(function () use ($type, $object): void {
                if (in_array($type, ['invoice.created', 'invoice.paid', 'invoice.payment_failed'], true)) {
                    $this->syncInvoice($type, is_array($object) ? $object : []);
                }

                if (in_array($type, ['customer.subscription.updated', 'customer.subscription.deleted'], true)) {
                    $this->syncSubscription($type, is_array($object) ? $object : []);
                }
            });

            $webhook->status = 'processed';
            $webhook->processed_at = now();
            $webhook->error_message = null;
            $webhook->save();
        } catch (\Throwable $e) {
            $webhook->status = 'failed';
            $webhook->error_message = mb_substr($e->getMessage(), 0, 1000);
            $webhook->save();

            throw $e;
        }
    }

    private function syncInvoice(string $type, array $invoice): void
    {
        $customerId = (string) ($invoice['customer'] ?? '');
        $stripeInvoiceId = (string) ($invoice['id'] ?? '');
        if ($customerId === '' || $stripeInvoiceId === '') {
            return;
        }

        $subscription = Subscription::query()
            ->where('stripe_customer_id', $customerId)
            ->latest('created_at')
            ->first();
        if (! $subscription) {
            return;
        }

        $status = match ($type) {
            'invoice.paid' => 'paid',
            'invoice.payment_failed' => 'past_due',
            default => (string) ($invoice['status'] ?? 'draft'),
        };

        Invoice::query()->updateOrCreate(
            ['stripe_invoice_id' => $stripeInvoiceId],
            [
                'tenant_id' => $subscription->tenant_id,
                'subscription_id' => $subscription->id,
                'invoice_number' => (string) ($invoice['number'] ?? ''),
                'status' => $status,
                'subtotal_cents' => (int) (($invoice['subtotal'] ?? 0) / 1),
                'tax_cents' => (int) (($invoice['tax'] ?? 0) / 1),
                'total_cents' => (int) (($invoice['total'] ?? 0) / 1),
                'currency' => strtoupper((string) ($invoice['currency'] ?? 'usd')),
                'hosted_invoice_url' => (string) ($invoice['hosted_invoice_url'] ?? ''),
                'issued_at' => isset($invoice['created']) ? now()->setTimestamp((int) $invoice['created']) : null,
                'due_at' => isset($invoice['due_date']) ? now()->setTimestamp((int) $invoice['due_date']) : null,
                'paid_at' => $type === 'invoice.paid' ? now() : null,
            ]
        );

        if ($type === 'invoice.payment_failed') {
            $subscription->status = 'past_due';
            $subscription->save();
        } elseif ($type === 'invoice.paid') {
            $subscription->status = 'active';
            $subscription->save();
        }
    }

    private function syncSubscription(string $type, array $data): void
    {
        $stripeSubscriptionId = (string) ($data['id'] ?? '');
        if ($stripeSubscriptionId === '') {
            return;
        }

        $subscription = Subscription::query()
            ->where('stripe_subscription_id', $stripeSubscriptionId)
            ->first();
        if (! $subscription) {
            return;
        }

        if ($type === 'customer.subscription.deleted') {
            $subscription->status = 'canceled';
            $subscription->canceled_at = now();
            $subscription->save();

            return;
        }

        $stripeStatus = (string) ($data['status'] ?? 'active');
        $subscription->status = match ($stripeStatus) {
            'trialing' => 'trialing',
            'active' => 'active',
            'past_due' => 'past_due',
            'canceled' => 'canceled',
            'unpaid' => 'suspended',
            default => $subscription->status,
        };
        $subscription->current_period_start = isset($data['current_period_start']) ? now()->setTimestamp((int) $data['current_period_start']) : $subscription->current_period_start;
        $subscription->current_period_end = isset($data['current_period_end']) ? now()->setTimestamp((int) $data['current_period_end']) : $subscription->current_period_end;

        $priceId = (string) data_get($data, 'items.data.0.price.id', '');
        if ($priceId !== '') {
            $plan = Plan::query()
                ->where('stripe_price_monthly_id', $priceId)
                ->orWhere('stripe_price_yearly_id', $priceId)
                ->first();
            if ($plan) {
                $subscription->plan_id = $plan->id;
            }
        }

        $subscription->save();
    }
}
