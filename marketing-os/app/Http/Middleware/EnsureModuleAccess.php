<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Services\Access\ModuleAccess;
use App\Support\NavModules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureModuleAccess
{
    use ResolvesWorkspace;

    public function __construct(private ModuleAccess $modules) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $user->is_superadmin) {
            return $next($request);
        }

        $module = NavModules::fromRouteName($request->route()?->getName());
        if (! $module) {
            return $next($request);
        }

        $workspace = $this->workspace($request);
        if (! $this->modules->canAccess($user, $workspace, $module)) {
            $fallback = $this->modules->firstAllowedRoute($user, $workspace);

            return redirect()
                ->route($fallback)
                ->with('error', 'You do not have access to that section.');
        }

        return $next($request);
    }
}
