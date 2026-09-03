<?php

namespace App\Support;

class ActivityLabels
{
    /** @var array<string, string> */
    private const ROUTES = [
        'social.posts.store' => 'Created social post',
        'social.posts.update' => 'Updated social post',
        'social.posts.destroy' => 'Deleted social post',
        'social.posts.publish' => 'Published social post',
        'social.posts.approve' => 'Approved social post',
        'social.analytics.sync' => 'Synced social engagement',
        'social.posts.analytics.sync' => 'Synced post engagement',
        'social.accounts.disconnect' => 'Disconnected social account',
        'social.accounts.destroy' => 'Removed social account',
        'workspaces.store' => 'Created workspace',
        'workspaces.switch' => 'Switched workspace',
        'workspaces.profile.update' => 'Updated workspace profile',
        'workspaces.members.store' => 'Invited team member',
        'workspaces.members.update' => 'Updated team member role',
        'workspaces.members.destroy' => 'Removed team member',
        'workspaces.modules.update' => 'Updated workspace modules',
        'workspaces.social-platforms.update' => 'Updated SMM platforms',
        'workspaces.members.modules.update' => 'Updated member module access',
        'brand.store' => 'Created brand kit',
        'brand.update' => 'Updated brand kit',
        'brand.destroy' => 'Deleted brand kit',
        'brand.activate' => 'Activated brand kit',
        'media.store' => 'Uploaded media',
        'media.destroy' => 'Deleted media',
        'billing.checkout' => 'Started billing checkout',
        'billing.recharge' => 'Started credit recharge',
        'profile.update' => 'Updated profile',
        'profile.destroy' => 'Deleted account',
        'settings.integrations.update' => 'Updated provider keys',
    ];

    public static function forAction(string $action): string
    {
        if (isset(self::ROUTES[$action])) {
            return self::ROUTES[$action];
        }

        if (str_starts_with($action, 'user.')) {
            $route = substr($action, 5);

            return self::ROUTES[$route] ?? self::humanizeRoute($route);
        }

        return self::humanizeAction($action);
    }

    public static function group(string $action): string
    {
        $raw = strtolower($action);

        foreach ([
            'seo' => 'seo',
            'social' => 'social',
            'blog' => 'blog',
            'askefy' => 'blog',
            'media' => 'media',
            'brand' => 'brand',
            'billing' => 'billing',
            'admin' => 'admin',
            'integrat' => 'settings',
            'setting' => 'settings',
            'profile' => 'settings',
        ] as $needle => $group) {
            if (str_contains($raw, $needle)) {
                return $group;
            }
        }

        if (
            str_contains($raw, 'workspace')
            || str_starts_with($raw, 'member.')
        ) {
            return 'workspace';
        }

        return 'other';
    }

    public static function applyGroupFilter($query, string $group): void
    {
        match ($group) {
            'seo', 'social', 'media', 'brand', 'billing', 'admin' => $query->where(
                'action',
                'like',
                '%'.$group.'%'
            ),
            'blog' => $query->where(function ($builder) {
                $builder
                    ->where('action', 'like', '%blog%')
                    ->orWhere('action', 'like', '%askefy%');
            }),
            'settings' => $query->where(function ($builder) {
                $builder
                    ->where('action', 'like', '%setting%')
                    ->orWhere('action', 'like', '%integrat%')
                    ->orWhere('action', 'like', '%profile%');
            }),
            'workspace' => $query->where(function ($builder) {
                $builder
                    ->where('action', 'like', '%workspace%')
                    ->orWhere('action', 'like', 'member.%');
            }),
            'other' => $query->where(function ($builder) {
                $builder
                    ->where('action', 'not like', '%seo%')
                    ->where('action', 'not like', '%social%')
                    ->where('action', 'not like', '%blog%')
                    ->where('action', 'not like', '%askefy%')
                    ->where('action', 'not like', '%media%')
                    ->where('action', 'not like', '%brand%')
                    ->where('action', 'not like', '%billing%')
                    ->where('action', 'not like', 'admin.%')
                    ->where('action', 'not like', '%setting%')
                    ->where('action', 'not like', '%integrat%')
                    ->where('action', 'not like', '%workspace%')
                    ->where('action', 'not like', 'member.%');
            }),
            default => null,
        };
    }

    private static function humanizeRoute(string $route): string
    {
        $parts = explode('.', $route);
        $verb = array_pop($parts) ?: 'action';
        $area = implode(' ', $parts);

        return ucfirst(str_replace('_', ' ', $verb)).($area !== '' ? ' · '.str_replace('.', ' ', $area) : '');
    }

    private static function humanizeAction(string $action): string
    {
        return ucwords(str_replace(['.', '_'], ' ', $action));
    }
}
