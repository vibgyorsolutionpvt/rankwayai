<?php

namespace App\Services\Billing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RazorpayClient
{
    private const BASE_URL = 'https://api.razorpay.com/v1';

    public function configured(): bool
    {
        return filled(config('services.razorpay.key_id'))
            && filled(config('services.razorpay.key_secret'));
    }

    public function http(): PendingRequest
    {
        return Http::withBasicAuth(
            (string) config('services.razorpay.key_id'),
            (string) config('services.razorpay.key_secret'),
        )->timeout(25)->acceptJson();
    }

    public function appUrlIsPublic(): bool
    {
        $host = strtolower((string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: ''));

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return false;
        }

        if (str_ends_with($host, '.test') || str_ends_with($host, '.local') || str_ends_with($host, '.localhost')) {
            return false;
        }

        return true;
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
     *   return_url:?string,
     *   notes?:array<string, string>
     * }  $data
     * @return array{ok:bool, link_url:?string, link_id:?string, error:?string, raw?:array}
     */
    public function createPaymentLink(array $data): array
    {
        $currency = strtoupper((string) $data['currency']);
        $minor = $this->toMinorUnits((float) $data['amount'], $currency);

        $payload = [
            'amount' => $minor,
            'currency' => $currency,
            'description' => Str::limit($data['purpose'], 500, ''),
            'customer' => [
                'name' => $data['customer_name'] ?: 'rankwayAI customer',
                'email' => $data['customer_email'] ?: 'billing@example.com',
            ],
            'notify' => [
                'sms' => false,
                'email' => false,
            ],
            'reminder_enable' => false,
            'notes' => collect($data['notes'] ?? [])
                ->mapWithKeys(fn ($v, $k) => [(string) $k => (string) $v])
                ->all(),
        ];

        $returnUrl = $data['return_url'] ?? null;
        if (filled($returnUrl)) {
            $payload['callback_url'] = (string) $returnUrl;
            $payload['callback_method'] = 'get';
        }

        $response = $this->http()->post(self::BASE_URL.'/payment_links', $payload);

        $json = $response->json() ?? [];
        $linkUrl = (string) ($json['short_url'] ?? $json['shorturl'] ?? '');

        if (! $response->successful() || blank($linkUrl)) {
            return [
                'ok' => false,
                'link_url' => null,
                'link_id' => null,
                'error' => Str::limit($json['error']['description'] ?? $response->body(), 240),
                'raw' => $json,
            ];
        }

        return [
            'ok' => true,
            'link_url' => $linkUrl,
            'link_id' => (string) ($json['id'] ?? $data['link_id']),
            'error' => null,
            'raw' => $json,
        ];
    }

    /**
     * @return array{ok:bool, status:?string, amount_paid?:float, raw?:array, error?:string}
     */
    public function getPaymentLink(string $linkId): array
    {
        $response = $this->http()->get(self::BASE_URL.'/payment_links/'.rawurlencode($linkId));

        if (! $response->successful()) {
            $json = $response->json() ?? [];

            return [
                'ok' => false,
                'status' => null,
                'error' => Str::limit($json['error']['description'] ?? $response->body(), 240),
                'raw' => $json,
            ];
        }

        $json = $response->json() ?? [];
        $currency = strtoupper((string) ($json['currency'] ?? 'INR'));
        $paidMinor = (int) ($json['amount_paid'] ?? 0);

        return [
            'ok' => true,
            'status' => strtolower((string) ($json['status'] ?? '')),
            'amount_paid' => $this->fromMinorUnits($paidMinor, $currency),
            'raw' => $json,
        ];
    }

    public function verifyWebhookSignature(string $rawBody, ?string $signature): bool
    {
        $secret = (string) config('services.razorpay.webhook_secret');

        if (blank($secret)) {
            return app()->environment('testing')
                || str_starts_with((string) config('services.razorpay.key_id'), 'rzp_test_');
        }

        if (blank($signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    private function toMinorUnits(float $amount, string $currency): int
    {
        $factor = match ($currency) {
            'JPY' => 1,
            default => 100,
        };

        return (int) round($amount * $factor);
    }

    private function fromMinorUnits(int $minor, string $currency): float
    {
        $factor = match ($currency) {
            'JPY' => 1,
            default => 100,
        };

        return $minor / $factor;
    }
}
