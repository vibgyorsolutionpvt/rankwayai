<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Services\Access\ModuleAccess;
use App\Services\Admin\UserSimulator;
use App\Services\Billing\PlanAccess;
use App\Services\Workspaces\VisibleWorkspaceService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $workspaces = [];
        $activeWorkspace = null;
        $navigation = [];
        $plan = null;
        $simulator = app(UserSimulator::class);
        $simulatingUser = $simulator->isSimulating($request);
        $impersonator = $simulatingUser ? $simulator->impersonator($request) : null;
        $modules = app(ModuleAccess::class);
        $plans = app(PlanAccess::class);

        if ($user && (! $user->is_superadmin || $simulatingUser)) {
            $workspaces = app(VisibleWorkspaceService::class)
                ->forUser($user)
                ->map(fn (Workspace $workspace) => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'slug' => $workspace->slug,
                    'role' => $workspace->pivot->role,
                    'industry' => $workspace->resolvedIndustry(),
                    'city' => $workspace->resolvedCity(),
                ])
                ->values()
                ->all();

            $activeId = (int) $request->session()->get('active_workspace_id');
            $activeWorkspace = collect($workspaces)->firstWhere('id', $activeId)
                ?? ($workspaces[0] ?? null);

            if ($activeWorkspace && $activeId !== (int) $activeWorkspace['id']) {
                $request->session()->put('active_workspace_id', $activeWorkspace['id']);
            }

            $activeModel = $activeWorkspace
                ? Workspace::query()->find($activeWorkspace['id'])
                : null;

            $navigation = $modules->navItemsFor($user, $activeModel);
            if ($activeModel) {
                $plan = $plans->summary($activeModel);
            }
        } elseif ($user?->is_superadmin && ! $simulatingUser) {
            $impersonateId = (int) $request->session()->get('impersonate_workspace_id');
            if ($impersonateId) {
                $impersonated = Workspace::query()->find($impersonateId);
                if ($impersonated) {
                    $activeWorkspace = [
                        'id' => $impersonated->id,
                        'name' => $impersonated->name,
                        'slug' => $impersonated->slug,
                        'role' => 'owner',
                    ];
                    $workspaces = [$activeWorkspace];
                    $navigation = $modules->navItemsFor($user, $impersonated);
                } else {
                    $navigation = $modules->navItemsFor($user, null);
                }
            } else {
                $navigation = $modules->navItemsFor($user, null);
            }
        }

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user
                    ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'email_verified_at' => $user->email_verified_at,
                        'is_superadmin' => (bool) $user->is_superadmin,
                        'is_active' => (bool) ($user->is_active ?? true),
                    ]
                    : null,
            ],
            'workspaces' => $workspaces,
            'activeWorkspace' => $activeWorkspace,
            'impersonating' => (bool) $request->session()->get('impersonate_workspace_id'),
            'simulatingUser' => $simulatingUser,
            'impersonator' => $impersonator
                ? [
                    'id' => $impersonator->id,
                    'name' => $impersonator->name,
                    'email' => $impersonator->email,
                ]
                : null,
            'navigation' => $navigation,
            'plan' => $plan,
            'can_create_workspace' => $user && (! $user->is_superadmin || $simulatingUser)
                ? $plans->canCreateWorkspace($user)
                : false,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'keyword_research' => fn () => $request->session()->get('keyword_research'),
                'share_open_url' => fn () => $request->session()->get('share_open_url'),
                'ai_compose' => fn () => $request->session()->get('ai_compose'),
                'ai_prompt' => fn () => $request->session()->get('ai_prompt'),
                'ai_offer' => fn () => $request->session()->get('ai_offer'),
            ],
        ];
    }
}
