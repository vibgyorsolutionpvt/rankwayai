<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\ModuleAccess;
use App\Support\NavModules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformAdminController extends Controller
{
    public function dashboard(Request $request, ModuleAccess $modules): Response
    {
        $users = User::query()
            ->withCount('workspaces')
            ->orderByDesc('is_superadmin')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_superadmin' => $user->is_superadmin,
                'workspaces_count' => $user->workspaces_count,
                'email_verified_at' => $user->email_verified_at,
                'created_at' => $user->created_at?->toDateString(),
            ]);

        $workspaces = Workspace::query()
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'members_count' => $workspace->users_count,
                'created_at' => $workspace->created_at?->toDateString(),
            ]);

        return Inertia::render('Admin/Dashboard', [
            'stats' => [
                'users' => User::query()->count(),
                'clients' => User::query()->where('is_superadmin', false)->count(),
                'workspaces' => Workspace::query()->count(),
                'superadmins' => User::query()->where('is_superadmin', true)->count(),
            ],
            'users' => $users,
            'workspaces' => $workspaces,
            'menus' => $modules->platformMenuStates(),
        ]);
    }

    public function updateMenu(Request $request, string $key, ModuleAccess $modules): RedirectResponse
    {
        abort_unless(in_array($key, NavModules::keys(), true), 404);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $modules->setPlatformMenu($key, (bool) $data['enabled']);

        return back()->with(
            'success',
            ($data['enabled'] ? 'Enabled' : 'Disabled').' '.$key.' for all clients'
        );
    }

    public function home(Request $request, ModuleAccess $modules): RedirectResponse
    {
        if ($request->user()?->is_superadmin) {
            return redirect()->route('admin.dashboard');
        }

        $user = $request->user();
        $activeId = (int) $request->session()->get('active_workspace_id');
        $workspace = null;
        if ($user) {
            if ($activeId) {
                $workspace = $user->workspaces()->where('workspaces.id', $activeId)->first();
            }
            $workspace ??= $user->workspaces()->orderBy('name')->first();
        }

        return redirect()->route($modules->firstAllowedRoute($user, $workspace));
    }
}
