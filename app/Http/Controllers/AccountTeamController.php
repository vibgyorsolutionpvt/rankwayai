<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Services\Workspaces\AgencyTeamService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountTeamController extends Controller
{
    public function invite(Request $request, AgencyTeamService $teams): RedirectResponse
    {
        $owner = $request->user();
        abort_unless($owner && ! $owner->is_superadmin, 403);

        $owned = $teams->ownedWorkspaces($owner);
        abort_if($owned->isEmpty(), 403);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'role' => ['required', 'string', Rule::in([
                WorkspaceRole::Admin->value,
                WorkspaceRole::Editor->value,
                WorkspaceRole::Viewer->value,
            ])],
            'workspace_ids' => ['required', 'array', 'min:1'],
            'workspace_ids.*' => ['integer'],
        ]);

        $teams->invite(
            $owner,
            $owner,
            $data['email'],
            $data['name'] ?? null,
            $data['role'],
            $data['workspace_ids']
        );

        return back()->with('success', 'Team member added to selected workspace(s).');
    }

    public function syncWorkspaces(Request $request, User $user, AgencyTeamService $teams): RedirectResponse
    {
        $owner = $request->user();
        abort_unless($owner && ! $owner->is_superadmin, 403);

        $data = $request->validate([
            'workspace_ids' => ['present', 'array'],
            'workspace_ids.*' => ['integer'],
        ]);

        $teams->syncWorkspaces($owner, $owner, (int) $user->id, $data['workspace_ids']);

        return back()->with('success', 'Workspace access updated.');
    }
}
