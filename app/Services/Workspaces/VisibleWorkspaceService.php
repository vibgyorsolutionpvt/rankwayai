<?php

namespace App\Services\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;

class VisibleWorkspaceService
{
    /**
     * Workspaces shown in the switcher — hides empty personal owner shells
     * when the user is also a team member elsewhere.
     *
     * @return Collection<int, Workspace>
     */
    public function forUser(User $user): Collection
    {
        $all = $user->workspaces()->orderBy('name')->get();

        if ($all->isEmpty()) {
            return $all;
        }

        $hasTeamMembership = $all->contains(
            fn (Workspace $workspace) => ($workspace->pivot->role ?? '') !== WorkspaceRole::Owner->value
        );

        if (! $hasTeamMembership) {
            return $all;
        }

        return $all
            ->filter(function (Workspace $workspace) use ($user) {
                if (($workspace->pivot->role ?? '') !== WorkspaceRole::Owner->value) {
                    return true;
                }

                return $workspace->users()->where('users.id', '!=', $user->id)->exists();
            })
            ->values();
    }

    /**
     * Drop solo personal owner workspaces when user also belongs to agency teams.
     */
    public function pruneSoloPersonalWorkspaces(User $user): void
    {
        $all = $user->workspaces()->get();
        $hasTeamMembership = $all->contains(
            fn (Workspace $workspace) => ($workspace->pivot->role ?? '') !== WorkspaceRole::Owner->value
        );

        if (! $hasTeamMembership) {
            return;
        }

        foreach ($all as $workspace) {
            if (($workspace->pivot->role ?? '') !== WorkspaceRole::Owner->value) {
                continue;
            }

            if ($workspace->users()->count() > 1) {
                continue;
            }

            $workspace->users()->detach($user->id);
        }
    }
}
