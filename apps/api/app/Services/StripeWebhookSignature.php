<?php

namespace App\Services;

class StripeWebhookSignature
{
    public function verify(string $payload, ?string $header, string $secret, int $toleranceSeconds = 300): bool
    {
        if (! $header || $secret === '') {
            return false;
        }

        $parts = collect(explode(',', $header))
            ->mapWithKeys(function (string $entry) {
                $pair = explode('=', trim($entry), 2);

                return count($pair) === 2 ? [$pair[0] => $pair[1]] : [];
            });

        $timestamp = $parts->get('t');
        $signature = $parts->get('v1');
        if (! $timestamp || ! $signature) {
            return false;
        }

        if (abs(time() - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        $signedPayload = $timestamp.'.'.$payload;
        $expected = hash_hmac('sha256', $signedPayload, $secret);

        return hash_equals($expected, $signature);
    }
}
