<?php

namespace App\Services\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingAccountService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AgencyTeamService
{
    public function __construct(private BillingAccountService $billingAccounts) {}

    /**
     * @return Collection<int, Workspace>
     */
    public function ownedWorkspaces(User $owner): Collection
    {
        return $owner->workspaces()
            ->wherePivot('role', WorkspaceRole::Owner->value)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return list<array{
     *   id:int,
     *   name:string,
     *   email:string,
     *   workspaces:list<array{id:int,name:string,role:string}>
     * }>
     */
    public function roster(User $owner): array
    {
        $owned = $this->ownedWorkspaces($owner)->load('users');
        if ($owned->isEmpty()) {
            return [];
        }

        $byUser = [];

        foreach ($owned as $workspace) {
            foreach ($workspace->users as $user) {
                if ((int) $user->id === (int) $owner->id) {
                    continue;
                }

                $uid = (int) $user->id;
                if (! isset($byUser[$uid])) {
                    $byUser[$uid] = [
                        'id' => $uid,
                        'name' => $user->name,
                        'email' => $user->email,
                        'workspaces' => [],
                    ];
                }

                $byUser[$uid]['workspaces'][] = [
                    'id' => (int) $workspace->id,
                    'name' => $workspace->name,
                    'role' => (string) $user->pivot->role,
                ];
            }
        }

        usort($byUser, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return array_values($byUser);
    }

    /**
     * @param  list<int>  $workspaceIds
     */
    public function invite(
        User $owner,
        User $actor,
        string $email,
        ?string $name,
        string $role,
        array $workspaceIds
    ): User {
        $workspaces = $this->assertManageableWorkspaces($owner, $workspaceIds);
        $roleEnum = WorkspaceRole::from($role);

        if ($roleEnum === WorkspaceRole::Owner) {
            throw ValidationException::withMessages([
                'role' => ['Use workspace settings to transfer ownership.'],
            ]);
        }

        $email = strtolower(trim($email));
        $created = false;
        $user = User::query()->where('email', $email)->first();

        if ($user?->is_superadmin) {
            throw ValidationException::withMessages([
                'email' => ['This email belongs to a platform admin and cannot be added.'],
            ]);
        }

        if (! $user) {
            $user = User::query()->create([
                'name' => trim((string) $name) !== '' ? trim((string) $name) : Str::before($email, '@'),
                'email' => $email,
                'password' => Str::password(20),
                'email_verified_at' => now(),
                'is_superadmin' => false,
            ]);
            $created = true;
        }

        foreach ($workspaces as $workspace) {
            if (! $actor->can('assignRole', [$workspace, $roleEnum])) {
                abort(403, 'You cannot assign this role on '.$workspace->name.'.');
            }

            if ($workspace->hasMember($user)) {
                continue;
            }

            $workspace->users()->attach($user->id, ['role' => $roleEnum->value]);

            ActivityLog::record($workspace, $actor, $created ? 'member.invited' : 'member.added', [
                'user_id' => $user->id,
                'role' => $roleEnum->value,
                'created' => $created,
                'agency_team' => true,
            ]);
        }

        if ($created) {
            try {
                Password::sendResetLink(['email' => $user->email]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $user;
    }

    /**
     * @param  list<int>  $workspaceIds
     */
    public function syncWorkspaces(User $owner, User $actor, int $memberId, array $workspaceIds): void
    {
        $owned = $this->ownedWorkspaces($owner);
        $ownedIds = $owned->pluck('id')->map(fn ($id) => (int) $id)->all();
        $targetIds = array_values(array_intersect(
            array_map('intval', $workspaceIds),
            $ownedIds
        ));

        if ($memberId === (int) $owner->id) {
            throw ValidationException::withMessages([
                'workspace_ids' => ['You cannot change your own workspace access here.'],
            ]);
        }

        $member = User::query()->findOrFail($memberId);

        foreach ($owned as $workspace) {
            $wid = (int) $workspace->id;
            $isMember = $workspace->hasMember($member);
            $shouldHave = in_array($wid, $targetIds, true);

            if ($shouldHave && ! $isMember) {
                if (! $actor->can('manageMembers', $workspace)) {
                    abort(403);
                }
                $workspace->users()->attach($member->id, ['role' => WorkspaceRole::Editor->value]);
                ActivityLog::record($workspace, $actor, 'member.added', [
                    'user_id' => $member->id,
                    'role' => WorkspaceRole::Editor->value,
                    'agency_team' => true,
                ]);
            } elseif (! $shouldHave && $isMember) {
                if (! $actor->can('manageMembers', $workspace)) {
                    abort(403);
                }
                $pivotRole = $workspace->roleFor($member);
                if ($pivotRole === WorkspaceRole::Owner) {
                    continue;
                }
                $workspace->users()->detach($member->id);
                ActivityLog::record($workspace, $actor, 'member.removed', [
                    'user_id' => $member->id,
                    'agency_team' => true,
                ]);
            }
        }
    }

    /**
     * @param  list<int>  $workspaceIds
     * @return Collection<int, Workspace>
     */
    private function assertManageableWorkspaces(User $owner, array $workspaceIds): Collection
    {
        $owned = $this->ownedWorkspaces($owner);
        $ownedIds = $owned->pluck('id')->map(fn ($id) => (int) $id)->all();

        $ids = array_values(array_unique(array_map('intval', $workspaceIds)));
        if ($ids === []) {
            throw ValidationException::withMessages([
                'workspace_ids' => ['Select at least one workspace.'],
            ]);
        }

        foreach ($ids as $id) {
            if (! in_array($id, $ownedIds, true)) {
                throw ValidationException::withMessages([
                    'workspace_ids' => ['Invalid workspace selection.'],
                ]);
            }
        }

        return $owned->whereIn('id', $ids)->values();
    }
}
