<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Http\Requests\Workspace\StoreWorkspaceMemberRequest;
use App\Http\Requests\Workspace\StoreWorkspaceRequest;
use App\Http\Requests\Workspace\UpdateWorkspaceMemberRequest;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\ModuleAccess;
use App\Services\Billing\PlanAccess;
use App\Support\NavModules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkspacePageController extends Controller
{
    public function index(Request $request): Response
    {
        $workspaces = $request->user()
            ->workspaces()
            ->orderBy('name')
            ->get()
            ->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'role' => $workspace->pivot->role,
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
            'moduleCatalog' => $this->moduleCatalogPayload(app(ModuleAccess::class), $active ? Workspace::query()->find($active['id']) : null),
        ]);
    }

    public function store(StoreWorkspaceRequest $request): RedirectResponse
    {
        $this->authorize('create', Workspace::class);

        $plans = app(PlanAccess::class);
        if (! $plans->canCreateWorkspace($request->user())) {
            throw ValidationException::withMessages([
                'name' => $plans->denyCreateWorkspaceMessage($request->user()),
            ]);
        }

        $workspace = Workspace::create([
            'name' => $request->validated('name'),
        ]);

        $workspace->users()->attach($request->user()->id, [
            'role' => WorkspaceRole::Owner->value,
        ]);

        ActivityLog::record($workspace, $request->user(), 'workspace.created', [
            'name' => $workspace->name,
        ]);

        $request->session()->put('active_workspace_id', $workspace->id);

        return redirect()
            ->route('settings.index', ['tab' => 'workspace'])
            ->with('success', 'Workspace created');
    }

    public function switch(Request $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('view', $workspace);

        $request->session()->put('active_workspace_id', $workspace->id);

        $redirect = $request->string('redirect')->toString();

        return match ($redirect) {
            'seo' => redirect()->route('seo.index'),
            'today' => redirect()->route('today'),
            'billing' => redirect()->route('billing.index'),
            'social' => redirect()->route('social.index'),
            'settings' => redirect()->route('settings.index', ['tab' => 'workspace']),
            'back' => back(),
            default => redirect()->route('settings.index', ['tab' => 'workspace']),
        };
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
