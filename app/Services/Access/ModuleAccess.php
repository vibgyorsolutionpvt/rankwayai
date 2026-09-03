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
    /**
     * @return list<array{key:string,label:string,enabled:bool}>
     */
    public function platformMenuStates(): array
    {
        $this->ensurePlatformRows();
        $rows = PlatformMenu::query()->pluck('enabled', 'key');

        return collect(NavModules::catalog())
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
    public function globallyEnabledKeys(): array
    {
        // No short-lived cache: admin toggles must hide menus on the next client request.
        $this->ensurePlatformRows();

        return PlatformMenu::query()
            ->where('enabled', true)
            ->whereIn('key', NavModules::keys())
            ->orderBy('id')
            ->pluck('key')
            ->values()
            ->all();
    }

    public function setPlatformMenu(string $key, bool $enabled): void
    {
        abort_unless(in_array($key, NavModules::keys(), true), 422);

        PlatformMenu::query()->updateOrCreate(
            ['key' => $key],
            ['enabled' => $enabled]
        );

        // Drop any legacy cached copies from older deploys.
        Cache::forget('platform_menus.enabled');
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

    /**
     * @param  list<string>|null  $keys  null = all workspace modules
     */
    public function setMemberModules(Workspace $workspace, int $userId, ?array $keys): void
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
                            'params' => $meta['params'] ?? null,
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
                ],
                [
                    'key' => 'admin-users',
                    'label' => 'Users',
                    'route' => 'admin.users',
                    'match' => 'admin.users*',
                    'icon' => 'workspace',
                    'tone' => 'amber',
                ],
                [
                    'key' => 'admin-workspaces',
                    'label' => 'Workspaces',
                    'route' => 'admin.workspaces',
                    'match' => 'admin.workspaces*',
                    'icon' => 'workspace',
                    'tone' => 'sky',
                ],
                [
                    'key' => 'admin-billing',
                    'label' => 'Billing',
                    'route' => 'admin.billing',
                    'match' => 'admin.billing',
                    'icon' => 'platform',
                    'tone' => 'emerald',
                ],
                [
                    'key' => 'admin-activity',
                    'label' => 'Team activity',
                    'route' => 'admin.activity',
                    'match' => 'admin.activity',
                    'icon' => 'today',
                    'tone' => 'fuchsia',
                ],
                [
                    'key' => 'admin-jobs',
                    'label' => 'Jobs',
                    'route' => 'admin.jobs',
                    'match' => 'admin.jobs*',
                    'icon' => 'seo',
                    'tone' => 'rose',
                ],
                [
                    'key' => 'admin-system',
                    'label' => 'System',
                    'route' => 'admin.system',
                    'match' => 'admin.system*',
                    'icon' => 'brand',
                    'tone' => 'sky',
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
                    'params' => $meta['params'] ?? null,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function firstAllowedRoute(User $user, ?Workspace $workspace): string
    {
        $items = $this->navItemsFor($user, $workspace);

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
        $missing = array_diff(NavModules::keys(), $existing);

        foreach ($missing as $key) {
            PlatformMenu::query()->create([
                'key' => $key,
                'enabled' => true,
            ]);
        }
    }

    /**
     * @param  mixed  $keys
     * @return list<string>|null  null means "inherit all"
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

        return array_values(array_unique(array_filter(
            $keys,
            fn ($key) => is_string($key) && in_array($key, NavModules::keys(), true)
        )));
    }
}
