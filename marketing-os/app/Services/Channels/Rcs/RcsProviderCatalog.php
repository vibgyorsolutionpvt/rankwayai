<?php

namespace App\Services\Channels\Rcs;

use App\Models\Workspace;
use App\Services\Integrations\WorkspaceIntegrationService;

class RcsProviderCatalog
{
    /**
     * @return list<array{id:string,label:string,ready:bool,driver:string}>
     */
    public static function available(?Workspace $workspace = null): array
    {
        $integrations = $workspace ? app(WorkspaceIntegrationService::class) : null;
        $out = [];

        foreach (config('rcs.providers', []) as $id => $cfg) {
            $ready = self::isReady($id, $cfg, $workspace, $integrations);
            $out[] = [
                'id' => $id,
                'label' => (string) ($cfg['label'] ?? $id),
                'ready' => $ready,
                'driver' => (string) ($cfg['driver'] ?? 'http'),
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public static function ids(): array
    {
        return array_keys(config('rcs.providers', []));
    }

    public static function normalize(?string $provider, ?Workspace $workspace = null): string
    {
        $ids = self::ids();
        if ($provider && in_array($provider, $ids, true)) {
            return $provider;
        }

        $default = (string) config('rcs.default', 'sandbox');
        $integrations = $workspace ? app(WorkspaceIntegrationService::class) : null;
        if (
            in_array($default, $ids, true)
            && self::isReady($default, config('rcs.providers.'.$default, []), $workspace, $integrations)
        ) {
            return $default;
        }

        foreach (['jio', 'airtel', 'vi', 'zavu', 'sandbox'] as $id) {
            $cfg = config('rcs.providers.'.$id);
            if (is_array($cfg) && self::isReady($id, $cfg, $workspace, $integrations)) {
                return $id;
            }
        }

        return 'sandbox';
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    public static function isReady(
        string $id,
        array $cfg,
        ?Workspace $workspace = null,
        ?WorkspaceIntegrationService $integrations = null
    ): bool {
        $driver = (string) ($cfg['driver'] ?? 'http');

        if ($driver === 'sandbox' || $id === 'sandbox') {
            return true;
        }

        if ($workspace && in_array($id, ['jio', 'airtel', 'vi', 'zavu'], true)) {
            $integrations ??= app(WorkspaceIntegrationService::class);
            if ($integrations->get($workspace, $id)) {
                return true;
            }
        }

        if (! ($cfg['enabled'] ?? false) && ! in_array($id, ['jio', 'airtel', 'vi'], true)) {
            // env-disabled non-carrier providers
        }

        if ($driver === 'zavu') {
            if ($workspace) {
                $integrations ??= app(WorkspaceIntegrationService::class);
                if (filled($integrations->zavuKey($workspace))) {
                    return true;
                }
            }

            return filled(config('services.zavu.key'));
        }

        if ($workspace && in_array($id, ['jio', 'airtel', 'vi'], true)) {
            $integrations ??= app(WorkspaceIntegrationService::class);
            $resolved = $integrations->rcsConfig($workspace, $id);

            return filled($resolved['base_url'] ?? null)
                && filled($resolved['client_id'] ?? null)
                && filled($resolved['client_secret'] ?? null);
        }

        if (! ($cfg['enabled'] ?? false)) {
            return false;
        }

        return filled($cfg['base_url'] ?? null)
            && filled($cfg['client_id'] ?? null)
            && filled($cfg['client_secret'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public static function config(string $provider, ?Workspace $workspace = null): array
    {
        $base = config('rcs.providers.'.$provider, []);
        if (! $workspace || ! in_array($provider, ['jio', 'airtel', 'vi'], true)) {
            return $base;
        }

        return app(WorkspaceIntegrationService::class)->rcsConfig($workspace, $provider);
    }
}
