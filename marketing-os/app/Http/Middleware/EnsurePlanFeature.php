<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Services\Billing\PlanAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanFeature
{
    use ResolvesWorkspace;

    public function __construct(private PlanAccess $plans) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $workspace = $this->workspace($request);

        if ($this->plans->allows($workspace, $feature)) {
            return $next($request);
        }

        return back()->with('error', $this->plans->denyMessage($feature));
    }
}
