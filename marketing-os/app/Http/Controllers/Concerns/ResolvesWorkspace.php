<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Workspace;
use Illuminate\Http\Request;

trait ResolvesWorkspace
{
    protected function workspace(Request $request): Workspace
    {
        $user = $request->user();
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
