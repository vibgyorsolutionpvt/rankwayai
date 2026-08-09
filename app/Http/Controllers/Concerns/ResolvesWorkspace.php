<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Workspace;
use Illuminate\Http\Request;

trait ResolvesWorkspace
{
    protected function workspace(Request $request): Workspace
    {
        $user = $request->user();

        // Super admin viewing a tenant workspace.
        if ($user?->is_superadmin) {
            $impersonateId = (int) $request->session()->get('impersonate_workspace_id');
            if ($impersonateId) {
                $workspace = Workspace::query()->find($impersonateId);
                abort_unless($workspace, 404, 'Workspace not found.');

                return $workspace;
            }
        }

        $activeId = (int) $request->session()->get('active_workspace_id');

        $workspace = null;
        if ($activeId) {
            $workspace = $user->workspaces()->where('workspaces.id', $activeId)->first();
        }

        if (! $workspace) {
            $workspace = $user->workspaces()->orderBy('name')->first();
            if ($workspace) {
                $request->session()->put('active_workspace_id', $workspace->id);
            }
        }

        abort_unless($workspace, 404, 'Create a workspace first.');

        return $workspace;
    }
}
