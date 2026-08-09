<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves optional X-Workspace-Id for future tenant-scoped routes.
 * Nested /api/workspaces/{workspace} routes remain the source of truth for Sprint 03.
 */
class EnsureWorkspaceMembership
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspaceId = $request->header('X-Workspace-Id');

        if ($workspaceId === null || $workspaceId === '') {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        $workspace = Workspace::query()->find($workspaceId);

        if (! $workspace || ! $workspace->hasMember($user)) {
            abort(403, 'You are not a member of this workspace.');
        }

        $request->attributes->set('workspace', $workspace);

        return $next($request);
    }
}
