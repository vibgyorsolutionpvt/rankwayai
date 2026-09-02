<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogTeamMemberActivity
{
    use ResolvesWorkspace;

    /** @var list<string> */
    private const SKIP_ROUTES = [
        'admin.*',
        'logout',
        'password.*',
        'verification.*',
        'webhooks.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $user = $request->user();
        if (! $user || $user->is_superadmin) {
            return $response;
        }

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $response;
        }

        $routeName = (string) ($request->route()?->getName() ?? '');
        if ($routeName === '' || $this->shouldSkip($routeName)) {
            return $response;
        }

        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        $workspace = null;
        try {
            $workspace = $this->workspace($request);
        } catch (\Throwable) {
            $workspace = null;
        }

        ActivityLog::record($workspace, $user, 'user.'.$routeName, [
            'method' => $request->method(),
            'path' => '/'.$request->path(),
            'ip' => $request->ip(),
            'status' => $response->getStatusCode(),
        ]);

        return $response;
    }

    private function shouldSkip(string $routeName): bool
    {
        foreach (self::SKIP_ROUTES as $pattern) {
            if (str_ends_with($pattern, '*') && str_starts_with($routeName, rtrim($pattern, '*'))) {
                return true;
            }
            if ($routeName === $pattern) {
                return true;
            }
        }

        return false;
    }
}
