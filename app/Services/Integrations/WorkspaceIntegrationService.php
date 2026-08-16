<?php

namespace App\Services\Integrations;

use App\Models\Workspace;
use App\Models\WorkspaceIntegration;

class WorkspaceIntegrationService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function dashboard(Workspace $workspace): array
    {
        $rows = WorkspaceIntegration::query()
            ->where('workspace_id', $workspace->id)
            ->get()
            ->keyBy('provider');

        $out = [];
        foreach (IntegrationCatalog::definitions() as $def) {
            /** @var WorkspaceIntegration|null $row */
            $row = $rows->get($def['id']);
            $client = $row
                ? $row->toClientArray($def['fields'])
                : [
                    'id' => null,
                    'category' => $def['category'],
                    'provider' => $def['id'],
                    'enabled' => false,
                    'status' => 'disconnected',
                    'connected' => false,
                    'fields' => collect($def['fields'])->mapWithKeys(fn ($f) => [
                        $f['key'] => ['configured' => false, 'hint' => null],
                    ])->all(),
                    'last_error' => null,
                    'connected_at' => null,
                    'updated_at' => null,
                ];

            $extra = [];
            if ($def['id'] === 'google_gsc') {
                $extra['redirect_uri'] = url('/seo/gsc/callback');
            }

            $out[] = array_merge($client, [
                'label' => $def['label'],
                'blurb' => $def['blurb'],
                'field_defs' => $def['fields'],
                'platform_fallback' => $this->platformFallbackReady($def['id']),
            ], $extra);
        }

        return $out;
    }

    public function upsert(Workspace $workspace, string $provider, array $input, bool $enabled = true): WorkspaceIntegration
    {
        $def = IntegrationCatalog::find($provider);
        if (! $def) {
            throw new \InvalidArgumentException('Unknown provider.');
        }

        $row = WorkspaceIntegration::query()->firstOrNew([
            'workspace_id' => $workspace->id,
            'provider' => $provider,
        ]);

        $existing = $row->credentials ?? [];
        $next = $existing;

        foreach ($def['fields'] as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $input)) {
                continue;
            }
            $value = $input[$key];
            // Keep previous secret if blank submitted (user left “leave unchanged”).
            if (($field['secret'] ?? false) && blank($value)) {
                continue;
            }
            $next[$key] = is_string($value) ? trim($value) : $value;
        }

        $ready = $this->credentialsReady($def, $next);

        $row->fill([
            'category' => $def['category'],
            'enabled' => $enabled,
            'credentials' => $next,
            'status' => $ready && $enabled ? 'connected' : 'disconnected',
            'last_error' => $ready ? null : 'Missing required credentials.',
            'connected_at' => $ready && $enabled ? ($row->connected_at ?: now()) : null,
        ]);
        $row->save();

        return $row->fresh();
    }

    public function disconnect(Workspace $workspace, string $provider): void
    {
        WorkspaceIntegration::query()
            ->where('workspace_id', $workspace->id)
            ->where('provider', $provider)
            ->update([
                'enabled' => false,
                'status' => 'disconnected',
                'connected_at' => null,
            ]);
    }

    public function forget(Workspace $workspace, string $provider): void
    {
        WorkspaceIntegration::query()
            ->where('workspace_id', $workspace->id)
            ->where('provider', $provider)
            ->delete();
    }

    public function get(Workspace $workspace, string $provider): ?WorkspaceIntegration
    {
        return WorkspaceIntegration::query()
            ->where('workspace_id', $workspace->id)
            ->where('provider', $provider)
            ->where('enabled', true)
            ->where('status', 'connected')
            ->first();
    }

    public function credential(Workspace $workspace, string $provider, string $key, mixed $default = null): mixed
    {
        $row = $this->get($workspace, $provider);

        return $row?->credential($key, $default) ?? $default;
    }

    public function isConnected(Workspace $workspace, string $provider): bool
    {
        return $this->get($workspace, $provider) !== null
            || $this->platformFallbackReady($provider);
    }

    /**
     * Resolve RCS HTTP config: workspace first, then env config/rcs.php.
     *
     * @return array<string, mixed>
     */
    public function rcsConfig(Workspace $workspace, string $provider): array
    {
        $base = config('rcs.providers.'.$provider, []);
        $row = $this->get($workspace, $provider);
        if (! $row) {
            return $base;
        }

        return array_merge($base, [
            'enabled' => true,
            'driver' => 'http',
            'base_url' => $row->credential('base_url') ?: ($base['base_url'] ?? null),
            'client_id' => $row->credential('client_id') ?: ($base['client_id'] ?? null),
            'client_secret' => $row->credential('client_secret') ?: ($base['client_secret'] ?? null),
            'agent_id' => $row->credential('agent_id') ?: ($base['agent_id'] ?? null),
        ]);
    }

    public function zavuKey(Workspace $workspace): ?string
    {
        return $this->credential($workspace, 'zavu', 'api_key')
            ?: config('services.zavu.key');
    }

    public function zavuBaseUrl(Workspace $workspace): string
    {
        return (string) ($this->credential($workspace, 'zavu', 'base_url')
            ?: config('services.zavu.base_url', 'https://api.zavu.dev'));
    }

    public function hasWhatsappMeta(Workspace $workspace): bool
    {
        return $this->whatsappMetaConfig($workspace) !== null;
    }

    /**
     * @return array{phone_number_id:string,waba_id:?string,access_token:string,app_secret:?string,verify_token:string,api_version:string}|null
     */
    public function whatsappMetaConfig(Workspace $workspace): ?array
    {
        $row = $this->get($workspace, 'whatsapp_meta');
        if (! $row) {
            // Platform env fallback for single-tenant / shared Meta app
            $phoneId = config('services.meta.whatsapp_phone_number_id');
            $token = config('services.meta.whatsapp_access_token');
            $verify = config('services.meta.whatsapp_verify_token');
            if (blank($phoneId) || blank($token) || blank($verify)) {
                return null;
            }

            return [
                'phone_number_id' => (string) $phoneId,
                'waba_id' => config('services.meta.whatsapp_waba_id'),
                'access_token' => (string) $token,
                'app_secret' => config('services.meta.app_secret') ?: config('services.meta.whatsapp_app_secret'),
                'verify_token' => (string) $verify,
                'api_version' => (string) (config('services.meta.whatsapp_api_version') ?: 'v21.0'),
            ];
        }

        $phoneId = (string) ($row->credential('phone_number_id') ?: '');
        $token = (string) ($row->credential('access_token') ?: '');
        $verify = (string) ($row->credential('verify_token') ?: '');
        if (blank($phoneId) || blank($token) || blank($verify)) {
            return null;
        }

        return [
            'phone_number_id' => $phoneId,
            'waba_id' => filled($row->credential('waba_id')) ? (string) $row->credential('waba_id') : null,
            'access_token' => $token,
            'app_secret' => filled($row->credential('app_secret'))
                ? (string) $row->credential('app_secret')
                : (config('services.meta.app_secret') ?: null),
            'verify_token' => $verify,
            'api_version' => (string) ($row->credential('api_version') ?: 'v21.0'),
        ];
    }

    /**
     * WhatsApp delivery: Meta Cloud API → Zavu → sandbox.
     */
    public function whatsappProvider(Workspace $workspace): string
    {
        if ($this->hasWhatsappMeta($workspace)) {
            return 'meta';
        }

        if (filled($this->zavuKey($workspace))) {
            return 'zavu';
        }

        return 'sandbox';
    }

    public function hasSmtp(Workspace $workspace): bool
    {
        return $this->get($workspace, 'smtp') !== null;
    }

    /**
     * @return array{host:string,port:int,encryption:string,username:string,password:string,from_address:string,from_name:?string}|null
     */
    public function smtpConfig(Workspace $workspace): ?array
    {
        $row = $this->get($workspace, 'smtp');
        if (! $row) {
            return null;
        }

        $encryption = strtolower((string) ($row->credential('encryption') ?: 'tls'));
        if (! in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $encryption = 'tls';
        }

        $host = (string) $row->credential('host');
        $username = (string) $row->credential('username');
        $password = (string) $row->credential('password');
        $from = (string) $row->credential('from_address');
        $port = (int) ($row->credential('port') ?: ($encryption === 'ssl' ? 465 : 587));

        if (blank($host) || blank($username) || blank($password) || blank($from) || $port < 1) {
            return null;
        }

        return [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'password' => $password,
            'from_address' => $from,
            'from_name' => filled($row->credential('from_name'))
                ? (string) $row->credential('from_name')
                : null,
        ];
    }

    /**
     * @return array<string, string> platform => oauth|sandbox
     */
    public function socialModes(Workspace $workspace): array
    {
        return [
            'facebook' => $this->isConnected($workspace, 'meta') ? 'oauth' : 'sandbox',
            'instagram' => $this->isConnected($workspace, 'meta') ? 'oauth' : 'sandbox',
            'threads' => $this->isConnected($workspace, 'meta') ? 'oauth' : 'sandbox',
            'linkedin' => $this->isConnected($workspace, 'linkedin') ? 'oauth' : 'sandbox',
            'x' => $this->isConnected($workspace, 'x') ? 'oauth' : 'sandbox',
        ];
    }

    public function socialCredential(Workspace $workspace, string $provider, string $key): ?string
    {
        $val = $this->credential($workspace, $provider, $key);
        if (filled($val)) {
            return (string) $val;
        }

        // Threads App ID/secret fall back to Meta App ID/secret when not set separately.
        if ($provider === 'meta' && $key === 'threads_app_id') {
            return $this->socialCredential($workspace, 'meta', 'app_id');
        }
        if ($provider === 'meta' && $key === 'threads_app_secret') {
            return $this->socialCredential($workspace, 'meta', 'app_secret');
        }

        return match ($provider.'.'.$key) {
            'meta.app_id' => config('services.meta.app_id'),
            'meta.app_secret' => config('services.meta.app_secret'),
            'linkedin.client_id' => config('services.linkedin.client_id'),
            'linkedin.client_secret' => config('services.linkedin.client_secret'),
            'x.client_id' => config('services.x.client_id'),
            'x.client_secret' => config('services.x.client_secret'),
            default => null,
        };
    }

    /**
     * @return array{client_id:string,client_secret:string}|null
     */
    public function googleGscConfig(Workspace $workspace): ?array
    {
        $row = $this->get($workspace, 'google_gsc');
        $clientId = $row?->credential('client_id') ?: config('services.google.client_id');
        $clientSecret = $row?->credential('client_secret') ?: config('services.google.client_secret');

        if (blank($clientId) || blank($clientSecret)) {
            return null;
        }

        return [
            'client_id' => (string) $clientId,
            'client_secret' => (string) $clientSecret,
        ];
    }

    public function pagespeedApiKey(Workspace $workspace): ?string
    {
        $key = $this->credential($workspace, 'google_pagespeed', 'api_key')
            ?: config('services.google.pagespeed_key');

        return filled($key) ? (string) $key : null;
    }

    private function platformFallbackReady(string $provider): bool
    {
        $snap = ProviderStatus::snapshot();

        return match ($provider) {
            'meta' => (bool) ($snap['meta'] ?? false),
            'whatsapp_meta' => filled(config('services.meta.whatsapp_phone_number_id'))
                && filled(config('services.meta.whatsapp_access_token'))
                && filled(config('services.meta.whatsapp_verify_token')),
            'linkedin' => (bool) ($snap['linkedin'] ?? false),
            'x' => (bool) ($snap['x'] ?? false),
            'zavu' => (bool) ($snap['zavu'] ?? false),
            'google_gsc' => (bool) ($snap['google'] ?? false),
            'google_pagespeed' => (bool) ($snap['pagespeed'] ?? false),
            'jio', 'airtel', 'vi' => false, // only workspace or explicit env enable
            default => false,
        };
    }

    /**
     * @param  array{fields:list<array{key:string,required?:bool}>}  $def
     * @param  array<string, mixed>  $creds
     */
    private function credentialsReady(array $def, array $creds): bool
    {
        foreach ($def['fields'] as $field) {
            if (($field['required'] ?? false) && blank($creds[$field['key']] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
