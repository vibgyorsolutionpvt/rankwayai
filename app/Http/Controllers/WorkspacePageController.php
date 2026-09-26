<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Http\Requests\Workspace\StoreWorkspaceMemberRequest;
use App\Http\Requests\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\Workspace\UpdateWorkspaceMemberRequest;
use App\Jobs\CrawlAndAuditSeoSiteJob;
use App\Models\ActivityLog;
use App\Models\SeoSite;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\ModuleAccess;
use App\Services\Billing\PlanAccess;
use App\Services\Workspaces\ProvisionClientWorkspace;
use App\Services\Workspaces\VisibleWorkspaceService;
use App\Support\BusinessTypes;
use App\Support\DomainNormalizer;
use App\Support\NavModules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkspacePageController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaces = app(VisibleWorkspaceService::class)
            ->forUser($request->user())
            ->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'role' => $workspace->pivot->role,
                'industry' => $workspace->resolvedIndustry(),
                'city' => $workspace->resolvedCity(),
            ]);

        $activeId = (int) $request->session()->get('active_workspace_id');
        $active = $workspaces->firstWhere('id', $activeId) ?? $workspaces->first();

        $members = [];
        if ($active) {
            $request->session()->put('active_workspace_id', $active['id']);
            $workspace = Workspace::query()->findOrFail($active['id']);
            $members = $workspace->users()->orderBy('name')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
                'enabled_modules' => $this->decodeModules($user->pivot->enabled_modules ?? null),
            ]);
        }

        return Inertia::render('Workspaces/Index', [
            'workspaces' => $workspaces,
            'activeWorkspace' => $active,
            'members' => $members,
            'roles' => WorkspaceRole::values(),
            'onboarding' => $workspaces->isEmpty(),
            'moduleCatalog' => $this->moduleCatalogPayload(app(ModuleAccess::class), $active ? Workspace::query()->find($active['id']) : null),
        ]);
    }

    public function store(StoreWorkspaceRequest $request, ProvisionClientWorkspace $provision): RedirectResponse
    {
        $plans = app(PlanAccess::class);
        if (! $plans->canCreateWorkspace($request->user())) {
            throw ValidationException::withMessages([
                'domain' => $plans->denyCreateWorkspaceMessage($request->user()),
            ]);
        }

        $this->authorize('create', Workspace::class);

        $domain = DomainNormalizer::normalize($request->validated('domain'));
        if ($domain === '' || ! str_contains($domain, '.')) {
            throw ValidationException::withMessages([
                'domain' => 'Enter a valid domain (e.g. example.com).',
            ]);
        }

        $workspace = $provision->createOwned($request->user(), $domain);
        $workspace->forceFill([
            'website' => 'https://'.$domain,
        ])->save();

        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => $domain,
            'status' => 'connected',
            'crawl_frequency' => 'daily',
            'crawl_status' => 'crawling',
            'next_crawl_at' => now(),
            'gsc_connected' => false,
        ]);

        try {
            CrawlAndAuditSeoSiteJob::dispatchSync($site->id);
        } catch (\Throwable) {
            // Workspace stays; audit failure is surfaced below.
        }
        $site->refresh();

        $request->session()->put('active_workspace_id', $workspace->id);

        $pages = $site->pages()->count();
        $issues = $site->issues()->where('status', 'open')->count();

        if ($site->crawl_status === 'failed' || $pages === 0) {
            return redirect()
                ->route('seo.index', ['site' => $site->id])
                ->with(
                    'error',
                    'Workspace created for '.$domain.', but SEO audit failed'.($site->last_crawl_error ? ' — '.$site->last_crawl_error : '.')
                );
        }

        return redirect()
            ->route('seo.index', ['site' => $site->id])
            ->with('success', 'Workspace ready · '.$domain.' audited: '.$pages.' page(s), '.$issues.' open issue(s)');
    }

    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('view', $workspace);

        $request->session()->put('active_workspace_id', $workspace->id);
        $request->session()->put('check_social_on_workspace_switch', true);

        // Never carry compose draft / AI flash from the previous workspace.
        $request->session()->forget([
            'ai_compose',
            'ai_prompt',
            'ai_offer',
            'success',
            'error',
        ]);

        $redirect = $request->string('redirect')->toString();

        return match ($redirect) {
            'seo' => redirect()->route('seo.index'),
            'today' => redirect()->route('dashboard'),
            'billing' => redirect()->route('billing.index'),
            'social' => redirect()->route('social.index', ['view' => 'calendar']),
            'ai' => redirect()->route('social.index', ['view' => 'compose']),
            'settings' => redirect()->route('settings.index', ['tab' => 'workspace']),
            'back' => $this->redirectAfterWorkspaceSwitch($request),
            default => redirect()->route('settings.index', ['tab' => 'workspace']),
        };
    }

    /**
     * Stay on the same module when possible, but never keep a workspace-scoped
     * resource URL (edit/show/pdf) that will 404 in the new workspace.
     */
    private function redirectAfterWorkspaceSwitch(Request $request): RedirectResponse
    {
        $previous = url()->previous();
        $path = parse_url($previous, PHP_URL_PATH) ?: '/';
        $query = parse_url($previous, PHP_URL_QUERY);
        $pathWithQuery = $query ? $path.'?'.$query : $path;

        $safeRoute = $this->safeRouteForWorkspaceSwitch($path);
        if ($safeRoute !== null) {
            return redirect()->route($safeRoute);
        }

        // Same-origin list/index pages are fine to return to.
        $appUrl = rtrim((string) config('app.url'), '/');
        if (str_starts_with($previous, $appUrl) || str_starts_with($previous, '/')) {
            return redirect()->to($pathWithQuery);
        }

        return redirect()->route('dashboard');
    }

    private function safeRouteForWorkspaceSwitch(string $path): ?string
    {
        $map = [
            // Studio resource deep links → module indexes
            '#^/studio/cards/[^/]+#' => 'studio.cards.index',
            '#^/studio/brochures/[^/]+#' => 'studio.brochures.index',
            '#^/studio/itineraries/[^/]+#' => 'studio.itineraries.index',
            '#^/studio/quotations/[^/]+#' => 'studio.quotations.index',
            // CRM lead detail
            '#^/crm/\d+#' => 'crm.index',
            '#^/crm/[0-9a-fA-F-]{8,}#' => 'crm.index',
            // Brand kit edit (POST-only deep paths shouldn't be returned to)
            '#^/brand/\d+#' => 'brand.edit',
            '#^/brand/[0-9a-fA-F-]{8,}#' => 'brand.edit',
            // Funnels with id in path (if any deep links appear later)
            '#^/funnels/\d+#' => 'funnels.index',
            // WhatsApp conversation deep links
            '#^/whatsapp/.+#' => 'whatsapp.index',
            // Blog post / draft deep links
            '#^/blog/.+#' => 'blog.index',
            // Media item deep links
            '#^/media/\d+#' => 'media.index',
            // Admin resource deep links
            '#^/admin/workspaces/\d+#' => 'admin.workspaces',
            '#^/admin/users/\d+#' => 'admin.users',
            '#^/admin/ai-logs/\d+#' => 'admin.ai-logs',
            '#^/admin/jobs/.+#' => 'admin.jobs',
        ];

        foreach ($map as $pattern => $route) {
            if (preg_match($pattern, $path)) {
                return $route;
            }
        }

        return null;
    }

    public function updateProfile(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'business_type' => ['required', 'string', Rule::in(BusinessTypes::keys())],
            'industry' => ['nullable', 'string', 'max:80', 'not_in:local business'],
            'tagline' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'city' => ['required', 'string', 'max:80', 'not_in:India'],
            'state' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:80'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:120'],
            'website' => ['nullable', 'string', 'max:200'],
            'services' => ['nullable', 'string', 'max:4000'],
            'products' => ['nullable', 'string', 'max:4000'],
            'target_audience' => ['nullable', 'string', 'max:2000'],
            'working_hours' => ['nullable', 'string', 'max:120'],
            'social_links' => ['nullable', 'array'],
            'social_links.facebook' => ['nullable', 'string', 'max:255'],
            'social_links.instagram' => ['nullable', 'string', 'max:255'],
            'social_links.linkedin' => ['nullable', 'string', 'max:255'],
            'social_links.youtube' => ['nullable', 'string', 'max:255'],
            'social_links.x' => ['nullable', 'string', 'max:255'],
            'social_links.threads' => ['nullable', 'string', 'max:255'],
        ], [
            'business_type.required' => 'Select a business type.',
            'city.required' => 'Enter a city.',
            'city.not_in' => 'Enter a real city — not just “India”.',
        ]);

        $typeLabel = BusinessTypes::label($data['business_type']);
        $industry = trim((string) ($data['industry'] ?? ''));
        if ($data['business_type'] === 'other') {
            if ($industry === '') {
                return back()->withErrors([
                    'industry' => 'Enter your business type when selecting Other.',
                ])->withInput();
            }
        } else {
            $industry = $typeLabel ?? $industry;
        }

        $social = collect($data['social_links'] ?? [])
            ->map(fn ($v) => trim((string) $v) ?: null)
            ->filter()
            ->all();

        $workspace->update([
            'business_type' => $data['business_type'],
            'industry' => $industry,
            'tagline' => trim((string) ($data['tagline'] ?? '')) ?: null,
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'city' => trim($data['city']),
            'state' => trim((string) ($data['state'] ?? '')) ?: null,
            'country' => trim((string) ($data['country'] ?? '')) ?: null,
            'postal_code' => trim((string) ($data['postal_code'] ?? '')) ?: null,
            'address' => trim((string) ($data['address'] ?? '')) ?: null,
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'whatsapp' => trim((string) ($data['whatsapp'] ?? '')) ?: null,
            'email' => trim((string) ($data['email'] ?? '')) ?: null,
            'website' => trim((string) ($data['website'] ?? '')) ?: null,
            'services' => $this->linesToList($data['services'] ?? null),
            'products' => $this->linesToList($data['products'] ?? null),
            'target_audience' => trim((string) ($data['target_audience'] ?? '')) ?: null,
            'working_hours' => trim((string) ($data['working_hours'] ?? '')) ?: null,
            'social_links' => $social !== [] ? $social : null,
        ]);

        app(\App\Services\Ai\AiContentService::class)->syncSettingsFromWorkspace($workspace->fresh());

        ActivityLog::record($workspace, $request->user(), 'workspace.profile_updated', [
            'business_type' => $workspace->business_type,
            'industry' => $workspace->industry,
            'city' => $workspace->city,
        ]);

        return back()->with('success', 'Business profile saved — AI and documents will use it automatically.');
    }

    /**
     * @return list<string>|null
     */
    private function linesToList(mixed $raw): ?array
    {
        $text = trim((string) ($raw ?? ''));
        if ($text === '') {
            return null;
        }

        $items = preg_split('/\r\n|\r|\n|,/', $text) ?: [];
        $items = array_values(array_filter(array_map(
            fn ($line) => trim((string) $line),
            $items
        ), fn ($line) => $line !== ''));

        return $items !== [] ? $items : null;
    }

    public function storeMember(StoreWorkspaceMemberRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('manageMembers', $workspace);

        $role = WorkspaceRole::from($request->validated('role'));

        if (! $request->user()->can('assignRole', [$workspace, $role])) {
            abort(403, 'You cannot assign this role.');
        }

        $email = strtolower(trim($request->validated('email')));
        $name = trim((string) ($request->validated('name') ?? ''));
        $created = false;

        $user = User::query()->where('email', $email)->first();

        if ($user?->is_superadmin) {
            throw ValidationException::withMessages([
                'email' => ['This email belongs to a platform admin and cannot be added as a member.'],
            ]);
        }

        if (! $user) {
            $user = User::query()->create([
                'name' => $name !== '' ? $name : Str::before($email, '@'),
                'email' => $email,
                'password' => Str::password(20),
                'email_verified_at' => now(),
                'is_superadmin' => false,
            ]);
            $created = true;
        }

        if ($workspace->hasMember($user)) {
            throw ValidationException::withMessages([
                'email' => ['User is already a member of this workspace.'],
            ]);
        }

        $workspace->users()->attach($user->id, ['role' => $role->value]);

        ActivityLog::record($workspace, $request->user(), $created ? 'member.invited' : 'member.added', [
            'user_id' => $user->id,
            'role' => $role->value,
            'created' => $created,
        ]);

        if ($created) {
            try {
                Password::sendResetLink(['email' => $user->email]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return back()->with(
            'success',
            $created
                ? 'User created and invited — password setup email sent (if mail is configured)'
                : 'Member added'
        );
    }

    public function updateMember(
        UpdateWorkspaceMemberRequest $request,
        Workspace $workspace,
        int $userId,
    ): RedirectResponse {
        $this->authorize('manageMembers', $workspace);

        $member = $workspace->users()->where('user_id', $userId)->firstOrFail();
        $currentRole = WorkspaceRole::from($member->pivot->role);
        $newRole = WorkspaceRole::from($request->validated('role'));

        if (! $request->user()->can('assignRole', [$workspace, $newRole])) {
            abort(403, 'You cannot assign this role.');
        }

        $actorRole = $workspace->roleFor($request->user());

        if ($currentRole === WorkspaceRole::Owner && $actorRole !== WorkspaceRole::Owner) {
            abort(403, 'Only an owner can change another owner.');
        }

        if ($actorRole === WorkspaceRole::Admin && $currentRole->rank() >= WorkspaceRole::Admin->rank()) {
            abort(403, 'Admins cannot change other admins or owners.');
        }

        if (
            $currentRole === WorkspaceRole::Owner
            && $newRole !== WorkspaceRole::Owner
            && $this->ownerCount($workspace) <= 1
        ) {
            throw ValidationException::withMessages([
                'role' => ['Workspace must keep at least one owner.'],
            ]);
        }

        $workspace->users()->updateExistingPivot($userId, ['role' => $newRole->value]);

        ActivityLog::record($workspace, $request->user(), 'member.role_updated', [
            'user_id' => $userId,
            'role' => $newRole->value,
        ]);

        return back()->with('success', 'Role updated');
    }

    public function updateModules(Request $request, Workspace $workspace, ModuleAccess $modules): RedirectResponse
    {
        $this->authorize('manageMembers', $workspace);

        $data = $request->validate([
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', 'in:'.implode(',', NavModules::keys())],
            'inherit_all' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['inherit_all'])) {
            $modules->setWorkspaceModules($workspace, null);
        } else {
            $allowed = array_values(array_intersect(
                $data['modules'] ?? [],
                $modules->globallyEnabledKeys()
            ));
            $modules->setWorkspaceModules($workspace, $allowed);
        }

        ActivityLog::record($workspace, $request->user(), 'workspace.modules_updated', [
            'modules' => $workspace->fresh()->enabled_modules,
        ]);

        return back()->with('success', 'Workspace modules updated');
    }

    public function updateSocialPlatforms(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('manageMembers', $workspace);

        $data = $request->validate([
            'platforms' => ['nullable', 'array'],
            'platforms.*' => ['string', 'in:'.implode(',', \App\Support\SocialPlatforms::keys())],
            'inherit_all' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['inherit_all'])) {
            $workspace->forceFill(['enabled_social_platforms' => null])->save();
        } else {
            $allowed = array_values(array_intersect(
                \App\Support\SocialPlatforms::normalize($data['platforms'] ?? []) ?? [],
                \App\Support\SocialPlatforms::globallyEnabledKeys()
            ));
            $workspace->forceFill(['enabled_social_platforms' => $allowed])->save();
        }

        ActivityLog::record($workspace, $request->user(), 'workspace.social_platforms_updated', [
            'platforms' => $workspace->fresh()->enabled_social_platforms,
        ]);

        return back()->with('success', 'SMM platforms updated');
    }

    public function updateMemberModules(
        Request $request,
        Workspace $workspace,
        int $userId,
        ModuleAccess $modules,
    ): RedirectResponse {
        $this->authorize('manageMembers', $workspace);
        abort_unless($workspace->users()->where('user_id', $userId)->exists(), 404);

        $data = $request->validate([
            'modules' => ['nullable', 'array'],
            'modules.*' => ['string', 'in:'.implode(',', NavModules::keys())],
            'inherit_all' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['inherit_all'])) {
            $modules->setMemberModules($workspace, $userId, null);
        } else {
            $allowed = array_values(array_intersect(
                $data['modules'] ?? [],
                $modules->workspaceEnabledKeys($workspace)
            ));
            $modules->setMemberModules($workspace, $userId, $allowed);
        }

        return back()->with('success', 'Member access updated');
    }

    public function destroyMember(Request $request, Workspace $workspace, int $userId): RedirectResponse
    {
        $this->authorize('manageMembers', $workspace);

        $member = $workspace->users()->where('user_id', $userId)->firstOrFail();
        $memberRole = WorkspaceRole::from($member->pivot->role);
        $actorRole = $workspace->roleFor($request->user());

        if ($memberRole === WorkspaceRole::Owner && $actorRole !== WorkspaceRole::Owner) {
            abort(403, 'Only an owner can remove another owner.');
        }

        if ($actorRole === WorkspaceRole::Admin && $memberRole->rank() >= WorkspaceRole::Admin->rank()) {
            abort(403, 'Admins cannot remove other admins or owners.');
        }

        if ($memberRole === WorkspaceRole::Owner && $this->ownerCount($workspace) <= 1) {
            throw ValidationException::withMessages([
                'user' => ['Workspace must keep at least one owner.'],
            ]);
        }

        $workspace->users()->detach($userId);

        ActivityLog::record($workspace, $request->user(), 'member.removed', [
            'user_id' => $userId,
        ]);

        return back()->with('success', 'Member removed');
    }

    private function ownerCount(Workspace $workspace): int
    {
        return $workspace->users()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->count();
    }

    /**
     * @return list<string>|null
     */
    private function decodeModules(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? array_values($decoded) : [];
        }

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return array{keys: list<string>, items: list<array{key:string,label:string,globally_enabled:bool,workspace_enabled:bool}>, workspace_modules: list<string>|null}
     */
    private function moduleCatalogPayload(ModuleAccess $modules, ?Workspace $workspace): array
    {
        $global = $modules->globallyEnabledKeys();
        $workspaceKeys = $workspace ? $modules->workspaceEnabledKeys($workspace) : $global;

        return [
            'keys' => NavModules::keys(),
            'workspace_modules' => $workspace?->enabled_modules,
            'items' => collect(NavModules::catalog())
                ->map(fn (array $meta, string $key) => [
                    'key' => $key,
                    'label' => $meta['label'],
                    'tone' => $meta['tone'],
                    'globally_enabled' => in_array($key, $global, true),
                    'workspace_enabled' => in_array($key, $workspaceKeys, true),
                ])
                ->values()
                ->all(),
        ];
    }
}
