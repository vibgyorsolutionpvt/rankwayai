<?php

namespace App\Services\Admin;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit\UserLoginLogger;
use App\Services\Workspaces\ProvisionClientWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class UserSimulator
{
    public function __construct(
        private UserLoginLogger $loginLogger,
        private ProvisionClientWorkspace $provision
    ) {}

    public function isSimulating(Request $request): bool
    {
        return (int) $request->session()->get('impersonator_id') > 0;
    }

    public function impersonator(Request $request): ?User
    {
        $id = (int) $request->session()->get('impersonator_id');

        return $id > 0 ? User::query()->find($id) : null;
    }

    public function start(Request $request, User $target): RedirectResponse
    {
        $admin = $request->user();
        abort_unless($admin?->is_superadmin, 403);
        abort_if($target->is_superadmin, 422, 'Cannot simulate another super admin.');
        abort_if($target->id === $admin->id, 422, 'You are already signed in as this user.');
        abort_unless($target->is_active, 422, 'This account is disabled.');

        $request->session()->put('impersonator_id', $admin->id);
        $request->session()->forget('impersonate_workspace_id');

        Auth::login($target);
        $request->session()->regenerate();

        $workspace = $target->workspaces()->orderBy('name')->first();
        if (! $workspace && ! $target->is_superadmin) {
            $workspace = $this->provision->for($target);
        }
        if ($workspace) {
            $request->session()->put('active_workspace_id', $workspace->id);
        }

        $this->loginLogger->recordLogin(
            $target,
            $request,
            simulated: true,
            simulatedBy: $admin->id
        );

        ActivityLog::record($workspace, $admin, 'admin.simulate_user', [
            'target_user_id' => $target->id,
            'target_email' => $target->email,
            'target_name' => $target->name,
        ]);

        return redirect()
            ->route('today')
            ->with('success', 'Simulating '.$target->name.' — actions are logged.');
    }

    public function stop(Request $request): RedirectResponse
    {
        $admin = $this->impersonator($request);
        if (! $admin?->is_superadmin) {
            $request->session()->forget('impersonator_id');

            return redirect()->route('login');
        }

        $this->loginLogger->recordLogout($request);

        $request->session()->forget('impersonator_id');
        $request->session()->forget('impersonate_workspace_id');
        $request->session()->forget('active_workspace_id');

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()
            ->route('admin.users')
            ->with('success', 'Stopped user simulation.');
    }
}
