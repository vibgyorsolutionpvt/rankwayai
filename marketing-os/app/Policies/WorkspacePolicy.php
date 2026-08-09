<?php

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->hasMember($user);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Workspace $workspace): bool
    {
        $role = $workspace->roleFor($user);

        return $role?->canUpdateWorkspace() ?? false;
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        $role = $workspace->roleFor($user);

        return $role?->canDeleteWorkspace() ?? false;
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        $role = $workspace->roleFor($user);

        return $role?->canManageMembers() ?? false;
    }

    public function assignRole(User $user, Workspace $workspace, WorkspaceRole $newRole): bool
    {
        $actorRole = $workspace->roleFor($user);

        if (! $actorRole?->canManageMembers()) {
            return false;
        }

        if ($newRole === WorkspaceRole::Owner) {
            return $actorRole === WorkspaceRole::Owner;
        }

        if ($actorRole === WorkspaceRole::Admin && $newRole->rank() >= WorkspaceRole::Admin->rank()) {
            return false;
        }

        return true;
    }
}
