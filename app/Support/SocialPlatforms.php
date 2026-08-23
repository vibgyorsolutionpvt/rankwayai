<?php

namespace App\Support;

use App\Models\PlatformSocialPlatform;
use Illuminate\Support\Facades\Cache;

class SocialPlatforms
{
    /**
     * @return array<string, array{label:string,tone:string}>
     */
    public static function catalog(): array
    {
        return [
            'facebook' => ['label' => 'Facebook', 'tone' => 'blue'],
            'instagram' => ['label' => 'Instagram', 'tone' => 'fuchsia'],
            'threads' => ['label' => 'Threads', 'tone' => 'ink'],
            'linkedin' => ['label' => 'LinkedIn', 'tone' => 'sky'],
            'x' => ['label' => 'X (Twitter)', 'tone' => 'zinc'],
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * @return list<array{key:string,label:string,tone:string,enabled:bool}>
     */
    public static function platformStates(): array
    {
        self::ensurePlatformRows();
        $rows = PlatformSocialPlatform::query()->pluck('enabled', 'key');

        return collect(self::catalog())
            ->map(fn (array $meta, string $key) => [
                'key' => $key,
                'label' => $meta['label'],
                'tone' => $meta['tone'],
                'enabled' => (bool) ($rows[$key] ?? true),
            ])
            ->values()
            ->all();
    }

    /** @return list<string> */
    public static function globallyEnabledKeys(): array
    {
        return Cache::remember('platform_social_platforms.enabled', 30, function () {
            self::ensurePlatformRows();

            return PlatformSocialPlatform::query()
                ->where('enabled', true)
                ->pluck('key')
                ->filter(fn ($key) => in_array($key, self::keys(), true))
                ->values()
                ->all();
        });
    }

    public static function setPlatformEnabled(string $key, bool $enabled): void
    {
        abort_unless(in_array($key, self::keys(), true), 422);

        PlatformSocialPlatform::query()->updateOrCreate(
            ['key' => $key],
            ['enabled' => $enabled]
        );

        Cache::forget('platform_social_platforms.enabled');
    }

    /**
     * Effective platforms for a workspace = global ∩ workspace config.
     * null workspace config = all globally enabled.
     *
     * @param  list<string>|null  $workspaceConfigured
     * @return list<string>
     */
    public static function enabled(?array $workspaceConfigured): array
    {
        $global = self::globallyEnabledKeys();

        if ($workspaceConfigured === null) {
            return $global;
        }

        return array_values(array_intersect($global, self::normalize($workspaceConfigured) ?? []));
    }

    /**
     * @param  mixed  $keys
     * @return list<string>|null
     */
    public static function normalize(mixed $keys): ?array
    {
        if ($keys === null) {
            return null;
        }

        if (is_string($keys)) {
            $decoded = json_decode($keys, true);
            $keys = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            $keys,
            fn ($key) => is_string($key) && in_array($key, self::keys(), true)
        )));
    }

    /**
     * All scheduled social posts require an image before save/publish.
     *
     * @param  list<string>|null  $platforms
     */
    public static function requiresImage(?array $platforms): bool
    {
        return count(self::normalize($platforms) ?? []) > 0;
    }

    private static function ensurePlatformRows(): void
    {
        $existing = PlatformSocialPlatform::query()->pluck('key')->all();
        $missing = array_diff(self::keys(), $existing);

        foreach ($missing as $key) {
            PlatformSocialPlatform::query()->create([
                'key' => $key,
                'enabled' => true,
            ]);
        }
    }
}
