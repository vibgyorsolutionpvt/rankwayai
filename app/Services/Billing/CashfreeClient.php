<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CashfreeClient
{
    public function configured(): bool
    {
        return filled(config('services.cashfree.client_id'))
            && filled(config('services.cashfree.client_secret'));
    }

    public function baseUrl(): string
    {
        $env = strtolower((string) config('services.cashfree.env', 'sandbox'));

        return $env === 'production'
            ? 'https://api.cashfree.com/pg'
            : 'https://sandbox.cashfree.com/pg';
    }

    public function http(): PendingRequest
    {
        return Http::withHeaders([
            'x-client-id' => (string) config('services.cashfree.client_id'),
            'x-client-secret' => (string) config('services.cashfree.client_secret'),
            'x-api-version' => (string) config('services.cashfree.api_version', '2023-08-01'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ])->timeout(25)->acceptJson();
    }

    /**
     * @param  array{
     *   link_id:string,
     *   amount:float,
     *   currency:string,
     *   purpose:string,
     *   customer_id:string,
     *   customer_email:?string,
     *   customer_phone:?string,
     *   customer_name:?string,
     *   return_url:string,
     *   notes?:array<string, string>
     * }  $data
     * @return array{ok:bool, link_url:?string, link_id:?string, error:?string, raw?:array}
     */
    public function createPaymentLink(array $data): array
    {
        $phone = preg_replace('/\D+/', '', (string) ($data['customer_phone'] ?? '')) ?: '9999999999';
        if (strlen($phone) < 10) {
            $phone = '9999999999';
        }

        $payload = [
            'link_id' => $data['link_id'],
            'link_amount' => round((float) $data['amount'], 2),
            'link_currency' => strtoupper($data['currency']),
            'link_purpose' => Str::limit($data['purpose'], 500, ''),
            'customer_details' => [
                'customer_id' => Str::limit($data['customer_id'], 50, ''),
                'customer_email' => $data['customer_email'] ?: 'billing@example.com',
                'customer_phone' => $phone,
                'customer_name' => $data['customer_name'] ?: 'rankwayAI customer',
            ],
            'link_meta' => [
                'return_url' => $data['return_url'],
            ],
            'link_notify' => [
                'send_email' => true,
                'send_sms' => false,
            ],
        ];

        if (! empty($data['notes']) && is_array($data['notes'])) {
            $payload['link_notes'] = collect($data['notes'])
                ->map(fn ($v) => (string) $v)
                ->all();
        }

        $response = $this->http()->post($this->baseUrl().'/links', $payload);

        if (! $response->successful() || blank($response->json('link_url'))) {
            return [
                'ok' => false,
                'link_url' => null,
                'link_id' => null,
                'error' => Str::limit($response->json('message') ?? $response->body(), 240),
                'raw' => $response->json() ?? [],
            ];
        }

        return [
            'ok' => true,
            'link_url' => (string) $response->json('link_url'),
            'link_id' => (string) ($response->json('link_id') ?? $data['link_id']),
            'error' => null,
            'raw' => $response->json() ?? [],
        ];
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signature, ?string $timestamp): bool
    {
        $secret = (string) (
            config('services.cashfree.webhook_secret')
            ?: config('services.cashfree.client_secret')
        );

        if (blank($secret) || blank($signature) || blank($timestamp)) {
            // Dev / missing secret: allow only when webhook secret intentionally blank and not production.
            return blank(config('services.cashfree.webhook_secret'))
                && strtolower((string) config('services.cashfree.env', 'sandbox')) !== 'production';
        }

        $expected = base64_encode(hash_hmac('sha256', $timestamp.$rawBody, $secret, true));

        return hash_equals($expected, $signature);
    }
}
