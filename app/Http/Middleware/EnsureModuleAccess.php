<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Services\Access\ModuleAccess;
use App\Services\Billing\PlanAccess;
use App\Services\Billing\PlanCatalog;
use App\Support\NavModules;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
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
        if ($this->modules->canAccess($user, $workspace, $module)) {
            return $next($request);
        }

        $plans = app(PlanAccess::class);
        $planLocked = ! $plans->hasUnlockedAccess($workspace)
            && ! in_array($module, PlanAccess::FREE_MODULES, true);

        // Plan lock: show upgrade panel on that section (no red flash on SEO).
        if ($planLocked) {
            $label = NavModules::catalog()[$module]['label'] ?? ucfirst($module);

            if ($request->inertia() || $request->expectsJson() || $request->isMethod('GET')) {
                return Inertia::render('Billing/PlanGate', [
                    'module' => $module,
                    'moduleLabel' => $label,
                    'message' => "{$label} needs a paid plan or credit top-up.",
                    'freeHighlights' => PlanCatalog::highlights('free'),
                ])->toResponse($request);
            }

            return redirect()
                ->route('billing.index')
                ->with('error', "{$label} needs a paid plan or credit top-up.");
        }

        // Workspace/member module deny — keep previous redirect behavior.
        $fallback = $this->modules->firstAllowedRoute($user, $workspace);

        return redirect()
            ->route($fallback)
            ->with('error', 'You do not have access to that section.');
    }
}
