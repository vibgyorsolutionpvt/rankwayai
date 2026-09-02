<?php

namespace App\Http\Middleware;

use App\Services\Workspaces\VisibleWorkspaceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureHasWorkspace
{
    /**
     * @var list<string>
     */
    private const ALLOWED_WITHOUT_WORKSPACE = [
        'workspaces.index',
        'workspaces.store',
        'workspaces.switch',
        'home',
        'profile.edit',
        'profile.update',
        'profile.destroy',
        'verification.notice',
        'verification.verify',
        'verification.send',
        'password.confirm',
        'password.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->is_superadmin) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();
        if ($routeName && str_starts_with($routeName, 'admin.')) {
            return $next($request);
        }

        if ($routeName && in_array($routeName, self::ALLOWED_WITHOUT_WORKSPACE, true)) {
            return $next($request);
        }

        if (app(VisibleWorkspaceService::class)->forUser($user)->isNotEmpty()) {
            return $next($request);
        }

        return redirect()
            ->route('workspaces.index')
            ->with('success', 'Create your first workspace to get started.');
    }
}
