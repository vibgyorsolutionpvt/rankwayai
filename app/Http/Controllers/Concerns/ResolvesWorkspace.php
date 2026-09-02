<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Workspace;
use App\Services\Workspaces\VisibleWorkspaceService;
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
        $visible = app(VisibleWorkspaceService::class)->forUser($user);

        $workspace = null;
        if ($activeId) {
            $workspace = $visible->firstWhere('id', $activeId);
        }

        if (! $workspace) {
            $workspace = $visible->first();
            if ($workspace) {
                $request->session()->put('active_workspace_id', $workspace->id);
            }
        }

        abort_unless($workspace, 404, 'Create a workspace first.');

        return $workspace;
    }
}
