<?php

namespace App\Services\Access;

use App\Enums\WorkspaceRole;
use App\Models\PlatformMenu;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\PlanAccess;
use App\Support\NavModules;
use Illuminate\Support\Facades\Cache;

class ModuleAccess
{
    private const PLATFORM_MENU_GROUP_DEFAULTS = [
        'business_section' => true,
        'marketing' => true,
        'leads' => false,
        'sales' => false,
        'business_studio' => true,
    ];

    /**
     * @return list<array{key:string,label:string,tone:string,enabled:bool}>
     */
    public function platformMenuStates(): array
    {
        $this->ensurePlatformRows();
        $rows = PlatformMenu::query()->pluck('enabled', 'key');

        $modules = collect(NavModules::catalog())
            ->map(fn (array $meta, string $key) => [
                'key' => $key,
                'label' => $meta['label'],
                'tone' => $meta['tone'],
                'enabled' => (bool) ($rows[$key] ?? true),
            ]);
        $parentMenus = collect(NavModules::menuGroups())
            ->map(fn (array $meta, string $key) => [
                'key' => $key,
                'label' => $meta['label'],
                'tone' => $meta['tone'],
                'enabled' => (bool) ($rows[$key] ?? self::PLATFORM_MENU_GROUP_DEFAULTS[$key]),
            ]);

        return $modules
            ->concat($parentMenus)
            ->values()
            ->all();
    }

    /**
     * @return list<array{key:string,label:string,tone:string,enabled:bool,children:list<array{key:string,label:string,tone:string,enabled:bool}>}>
     */
    public function platformMenuGroups(): array
    {
        $states = collect($this->platformMenuStates())->keyBy('key');

        return collect(NavModules::menuGroups())
            ->map(fn (array $group, string $key) => [
                'key' => $key,
                'label' => $group['label'],
                'tone' => $group['tone'],
                'enabled' => (bool) ($states[$key]['enabled'] ?? self::PLATFORM_MENU_GROUP_DEFAULTS[$key]),
                'children' => collect($group['children'])
                    ->map(fn (string $childKey) => $states[$childKey] ?? null)
                    ->filter()
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /** @return list<string> */
    public function globallyEnabledKeys(): array
    {
        // No short-lived cache: admin toggles must hide menus on the next client request.
        $this->ensurePlatformRows();
        $keys = PlatformMenu::query()
            ->where('enabled', true)
            ->whereIn('key', NavModules::keys())
            ->orderBy('id')
            ->pluck('key')
            ->values()
            ->all();

        foreach (NavModules::menuGroups() as $groupKey => $group) {
            if (! $this->isPlatformMenuEnabled($groupKey)) {
                $keys = array_values(array_diff($keys, $group['children']));
            }
        }

        return $keys;
    }

    public function setPlatformMenu(string $key, bool $enabled): void
    {
        abort_unless($this->supportsPlatformMenu($key), 422);

        PlatformMenu::query()->updateOrCreate(
            ['key' => $key],
            ['enabled' => $enabled]
        );

        // Drop any legacy cached copies from older deploys.
        Cache::forget('platform_menus.enabled');
    }

    public function supportsPlatformMenu(string $key): bool
    {
        return in_array($key, $this->platformMenuKeys(), true);
    }

    public function isRouteMenuEnabled(string $routeName): bool
    {
        $menuKey = NavModules::fromRouteName($routeName);

        return $menuKey === null || in_array($menuKey, $this->globallyEnabledKeys(), true);
    }

    public function isPlatformMenuEnabled(string $key): bool
    {
        $this->ensurePlatformRows();

        return (bool) PlatformMenu::query()->where('key', $key)->value('enabled');
    }

    /**
     * Modules enabled for a workspace (intersected with global menus).
     * Plan locks are applied in canAccess() so free users still see all menus.
     *
     * @return list<string>
     */
    public function workspaceEnabledKeys(Workspace $workspace): array
    {
        $global = $this->globallyEnabledKeys();
        $configured = $this->normalizeKeys($workspace->enabled_modules);

        if ($configured === null) {
            return $global;
        }

        return array_values(array_intersect($global, $configured));
    }

    /**
     * Modules listed in the sidebar for a user in this workspace.
     *
     * @return list<string>
     */
    public function userEnabledKeys(User $user, Workspace $workspace): array
    {
        if ($user->is_superadmin) {
            return NavModules::keys();
        }

        $workspaceKeys = $this->workspaceEnabledKeys($workspace);
        $membership = $workspace->users()->where('user_id', $user->id)->first();

        if (! $membership) {
            return [];
        }

        $role = WorkspaceRole::tryFrom($membership->pivot->role);
        // Owners/admins always keep settings so they can manage access.
        $memberConfigured = $this->normalizeKeys($membership->pivot->enabled_modules ?? null);

        if ($memberConfigured === null) {
            $keys = $workspaceKeys;
        } else {
            $keys = array_values(array_intersect($workspaceKeys, $memberConfigured));
        }

        // Owners/admins always keep settings so they can re-enable modules.
        if ($role && $role->canManageMembers() && ! in_array('settings', $keys, true) && in_array('settings', $this->globallyEnabledKeys(), true)) {
            $keys[] = 'settings';
        }

        return array_values(array_unique($keys));
    }

    public function canAccess(User $user, Workspace $workspace, string $moduleKey): bool
    {
        if (! in_array($moduleKey, $this->userEnabledKeys($user, $workspace), true)) {
            return false;
        }

        // Free plan: menus show, but non-SEO modules stay locked.
        $planKeys = app(PlanAccess::class)->modulesFor($workspace);

        return in_array($moduleKey, $planKeys, true);
    }

    /**
     * @param  list<string>|null  $keys  null = all currently global modules
     */
    public function setWorkspaceModules(Workspace $workspace, ?array $keys): void
    {
        $workspace->forceFill([
            'enabled_modules' => $keys === null ? null : $this->normalizeKeys($keys) ?? [],
        ])->save();
    }

    /** @return list<string>|null */
    public function normalizeModuleKeys(mixed $keys): ?array
    {
        return $this->normalizeKeys($keys);
    }

    /**
     * @param  list<string>|null  $keys  null = all workspace modules
     */
    public function setMemberModules(Workspace $workspace, string $userId, ?array $keys): void
    {
        $normalized = $keys === null ? null : ($this->normalizeKeys($keys) ?? []);

        $workspace->users()->updateExistingPivot($userId, [
            'enabled_modules' => $normalized === null ? null : json_encode(array_values($normalized)),
        ]);
    }

    /**
     * @return list<array{key:string,label:string,route:string,match:string,icon:string,tone:string,params?:array<string, string>}>
     */
    public function navItemsFor(User $user, ?Workspace $workspace): array
    {
        if ($user->is_superadmin) {
            // When viewing a client workspace, show the client module nav.
            if ($workspace) {
                $allowed = $this->globallyEnabledKeys();
                $catalog = NavModules::catalog();

                return collect($allowed)
                    ->map(function (string $key) use ($catalog) {
                        $meta = $catalog[$key] ?? null;
                        if (! $meta) {
                            return null;
                        }

                        return [
                            'key' => $key,
                            'label' => $meta['label'],
                            'route' => $meta['route'],
                            'match' => $meta['match'],
                            'icon' => $meta['icon'],
                            'tone' => $meta['tone'],
                            'group' => $meta['group'] ?? 'Work',
                            'params' => $meta['params'] ?? null,
                            'comingSoon' => $meta['comingSoon'] ?? false,
                        ];
                    })
                    ->filter()
                    ->values()
                    ->all();
            }

            return [
                [
                    'key' => 'admin',
                    'label' => 'Overview',
                    'route' => 'admin.dashboard',
                    'match' => 'admin.dashboard',
                    'icon' => 'platform',
                    'tone' => 'ink',
                    'group' => 'Admin',
                ],
                [
                    'key' => 'admin-users',
                    'label' => 'Users',
                    'route' => 'admin.users',
                    'match' => 'admin.users*',
                    'icon' => 'workspace',
                    'tone' => 'amber',
                    'group' => 'Admin',
                ],
                [
                    'key' => 'admin-workspaces',
                    'label' => 'Workspaces',
                    'route' => 'admin.workspaces',
                    'match' => 'admin.workspaces*',
                    'icon' => 'workspace',
                    'tone' => 'sky',
                    'group' => 'Admin',
                ],
                [
                    'key' => 'admin-billing',
                    'label' => 'Billing',
                    'route' => 'admin.billing',
                    'match' => 'admin.billing',
                    'icon' => 'platform',
                    'tone' => 'emerald',
                    'group' => 'Admin',
                ],
                [
                    'key' => 'admin-activity',
                    'label' => 'Team activity',
                    'route' => 'admin.activity',
                    'match' => 'admin.activity',
                    'icon' => 'today',
                    'tone' => 'fuchsia',
                    'group' => 'Admin',
                ],
                [
                    'key' => 'admin-ai-logs',
                    'label' => 'AI logs',
                    'route' => 'admin.ai-logs',
                    'match' => 'admin.ai-logs*',
                    'icon' => 'seo',
                    'tone' => 'signal',
                    'group' => 'Admin',
                ],
                [
                    'key' => 'admin-jobs',
                    'label' => 'Jobs',
                    'route' => 'admin.jobs',
                    'match' => 'admin.jobs*',
                    'icon' => 'seo',
                    'tone' => 'rose',
                    'group' => 'Admin',
                ],
                [
                    'key' => 'admin-system',
                    'label' => 'System',
                    'route' => 'admin.system',
                    'match' => 'admin.system*',
                    'icon' => 'brand',
                    'tone' => 'sky',
                    'group' => 'Admin',
                ],
            ];
        }

        if (! $workspace) {
            return [];
        }

        $allowed = $this->userEnabledKeys($user, $workspace);
        $catalog = NavModules::catalog();

        return collect($allowed)
            ->map(function (string $key) use ($catalog) {
                $meta = $catalog[$key] ?? null;
                if (! $meta) {
                    return null;
                }

                return [
                    'key' => $key,
                    'label' => $meta['label'],
                    'route' => $meta['route'],
                    'match' => $meta['match'],
                    'icon' => $meta['icon'],
                    'tone' => $meta['tone'],
                    'group' => $meta['group'] ?? 'Work',
                    'params' => $meta['params'] ?? null,
                    'comingSoon' => $meta['comingSoon'] ?? false,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function firstAllowedRoute(User $user, ?Workspace $workspace): string
    {
        $catalog = NavModules::catalog();
        $items = collect($this->navItemsFor($user, $workspace))
            ->reject(fn (array $item) => $catalog[$item['key']]['comingSoon'] ?? false)
            ->values()
            ->all();

        if ($workspace && ! $user->is_superadmin) {
            $planKeys = app(PlanAccess::class)->modulesFor($workspace);
            $items = collect($items)
                ->filter(fn (array $item) => in_array($item['key'], $planKeys, true))
                ->values()
                ->all();
        }

        $first = $items[0]['route'] ?? null;

        return $first ?: ($user->is_superadmin ? 'admin.dashboard' : 'profile.edit');
    }

    private function ensurePlatformRows(): void
    {
        $existing = PlatformMenu::query()->pluck('key')->all();
        $defaults = array_merge(
            array_fill_keys(NavModules::keys(), true),
            ['email' => false],
            self::PLATFORM_MENU_GROUP_DEFAULTS,
        );
        $legacyStudioEnabled = (bool) PlatformMenu::query()->where('key', 'studio')->value('enabled');
        if (in_array('studio', $existing, true)) {
            $defaults['business_card'] = $legacyStudioEnabled;
            $defaults['brochure'] = $legacyStudioEnabled;
        }
        $missing = array_diff(array_keys($defaults), $existing);

        foreach ($missing as $key) {
            PlatformMenu::query()->create([
                'key' => $key,
                'enabled' => $defaults[$key],
            ]);
        }
    }

    /** @return list<string> */
    private function platformMenuKeys(): array
    {
        return [...NavModules::keys(), ...array_keys(NavModules::menuGroups())];
    }

    /**
     * @return list<string>|null null means "inherit all"
     */
    private function normalizeKeys(mixed $keys): ?array
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

        $keys = collect($keys)
            ->flatMap(fn ($key) => $key === 'studio' ? ['business_card', 'brochure'] : [$key])
            ->all();

        return array_values(array_unique(array_filter(
            $keys,
            fn ($key) => is_string($key) && in_array($key, NavModules::keys(), true)
        )));
    }
}
