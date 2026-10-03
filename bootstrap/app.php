<?php

use App\Http\Middleware\EnsureHasWorkspace;
use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsurePlanFeature;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LogTeamMemberActivity;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            LogTeamMemberActivity::class,
        ]);

        $middleware->alias([
            'plan' => EnsurePlanFeature::class,
            'module' => EnsureModuleAccess::class,
            'workspace.setup' => EnsureHasWorkspace::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/stripe',
            'webhooks/razorpay',
            'webhooks/zavu/*',
            'webhooks/meta/whatsapp',
            'webhooks/meta/whatsapp/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
