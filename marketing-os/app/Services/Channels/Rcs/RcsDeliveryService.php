<?php

namespace App\Services\Channels\Rcs;

use App\Models\Workspace;
use App\Services\Integrations\WorkspaceIntegrationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class RcsDeliveryService
{
    public function __construct(private WorkspaceIntegrationService $integrations) {}

    /**
     * @return array{ok:bool, id:?string, error:?string}
     */
    public function send(
        string $provider,
        string $to,
        string $text,
        array $meta = [],
        ?Workspace $workspace = null
    ): array {
        $provider = RcsProviderCatalog::normalize($provider, $workspace);
        $cfg = RcsProviderCatalog::config($provider, $workspace);
        $driver = (string) ($cfg['driver'] ?? 'sandbox');

        if (
            $driver === 'sandbox'
            || $provider === 'sandbox'
            || ! RcsProviderCatalog::isReady($provider, $cfg, $workspace, $this->integrations)
        ) {
            return [
                'ok' => true,
                'id' => 'rcs_sandbox_'.Str::lower(Str::random(10)),
                'error' => null,
            ];
        }

        return match ($driver) {
            'zavu' => $this->sendViaZavu($to, $text, $meta, $workspace),
            default => $this->sendViaHttp($provider, $cfg, $to, $text, $meta),
        };
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @param  array<string, mixed>  $meta
     * @return array{ok:bool, id:?string, error:?string}
     */
    private function sendViaHttp(string $provider, array $cfg, string $to, string $text, array $meta): array
    {
        $url = rtrim((string) $cfg['base_url'], '/').'/'.ltrim((string) ($cfg['send_path'] ?? '/v1/messages'), '/');

        $payload = [
            'to' => $to,
            'text' => $text,
            'channel' => 'rcs',
            'agentId' => $cfg['agent_id'] ?? null,
            'metadata' => array_merge($meta, [
                'provider' => $provider,
            ]),
        ];

        try {
            $request = Http::timeout(25)->acceptJson();

            $auth = (string) ($cfg['auth'] ?? 'bearer');
            if ($auth === 'basic') {
                $request = $request->withBasicAuth(
                    (string) $cfg['client_id'],
                    (string) $cfg['client_secret']
                );
            } else {
                $request = $request
                    ->withToken((string) $cfg['client_secret'])
                    ->withHeaders([
                        'X-Client-Id' => (string) ($cfg['client_id'] ?? ''),
                    ]);
            }

            $response = $request->post($url, array_filter($payload, fn ($v) => $v !== null && $v !== ''));

            if ($response->successful()) {
                $id = $response->json('id')
                    ?? $response->json('messageId')
                    ?? $response->json('message.id')
                    ?? $provider.'_'.Str::random(8);

                return ['ok' => true, 'id' => (string) $id, 'error' => null];
            }

            return [
                'ok' => false,
                'id' => null,
                'error' => Str::limit($response->json('error.message') ?? $response->json('message') ?? $response->body(), 240),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'error' => Str::limit($e->getMessage(), 240)];
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{ok:bool, id:?string, error:?string}
     */
    private function sendViaZavu(string $to, string $text, array $meta, ?Workspace $workspace): array
    {
        $key = $workspace
            ? $this->integrations->zavuKey($workspace)
            : config('services.zavu.key');
        $base = $workspace
            ? $this->integrations->zavuBaseUrl($workspace)
            : config('services.zavu.base_url', 'https://api.zavu.dev');

        if (blank($key)) {
            return [
                'ok' => true,
                'id' => 'rcs_sandbox_'.Str::lower(Str::random(10)),
                'error' => null,
            ];
        }

        try {
            $response = Http::withToken($key)
                ->timeout(20)
                ->acceptJson()
                ->post(rtrim($base, '/').'/v1/messages', [
                    'to' => $to,
                    'channel' => 'sms',
                    'text' => $text,
                    'metadata' => array_merge($meta, ['atlas_channel' => 'rcs']),
                ]);

            if ($response->successful()) {
                $id = $response->json('id')
                    ?? $response->json('message.id')
                    ?? 'zavu_'.Str::random(8);

                return ['ok' => true, 'id' => (string) $id, 'error' => null];
            }

            return [
                'ok' => false,
                'id' => null,
                'error' => Str::limit($response->json('error.message') ?? $response->body(), 240),
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'error' => Str::limit($e->getMessage(), 240)];
        }
    }
}
