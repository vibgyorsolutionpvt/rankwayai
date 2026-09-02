<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Admin\UserSimulator;
use App\Services\Audit\UserLoginLogger;
use App\Services\Workspaces\ProvisionClientWorkspace;
use App\Services\Workspaces\VisibleWorkspaceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Login', [
            'canResetPassword' => Route::has('password.request'),
            'status' => session('status'),
        ]);
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(
        LoginRequest $request,
        ProvisionClientWorkspace $provision,
        UserLoginLogger $loginLogger
    ): RedirectResponse {
        $request->authenticate();

        $request->session()->regenerate();

        $user = $request->user();
        if ($user) {
            $loginLogger->recordLogin($user, $request);
        }

        if ($user && ! $user->is_superadmin) {
            app(VisibleWorkspaceService::class)->pruneSoloPersonalWorkspaces($user);
            $user = $user->fresh();
            $workspace = $provision->resolveActive($user);
            if ($workspace) {
                $request->session()->put('active_workspace_id', $workspace->id);
            } else {
                $request->session()->forget('active_workspace_id');
            }

            if (! $workspace) {
                return redirect()->intended(route('workspaces.index', absolute: false));
            }
        }

        return redirect()->intended(route('home', absolute: false));
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(
        Request $request,
        UserSimulator $simulator,
        UserLoginLogger $loginLogger
    ): RedirectResponse {
        if ($simulator->isSimulating($request)) {
            return $simulator->stop($request);
        }

        $loginLogger->recordLogout($request);

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
