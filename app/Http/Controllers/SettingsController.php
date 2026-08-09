<?php

namespace App\Http\Controllers;

use App\Enums\WorkspaceRole;
use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\ModuleAccess;
use App\Services\Billing\BillingService;
use App\Services\Integrations\IntegrationCatalog;
use App\Services\Integrations\WorkspaceIntegrationService;
use App\Support\NavModules;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    use ResolvesWorkspace;

    public function index(
        Request $request,
        WorkspaceIntegrationService $integrations,
        BillingService $billing,
        ModuleAccess $modules,
    ): Response {
        $tab = $request->string('tab')->toString();
        if (! in_array($tab, ['providers', 'workspace', 'account', 'billing'], true)) {
            $tab = 'providers';
        }

        $workspace = $this->workspace($request);

        $workspaces = $request->user()
            ->workspaces()
            ->orderBy('name')
            ->get()
            ->map(fn (Workspace $item) => [
                'id' => $item->id,
                'name' => $item->name,
                'slug' => $item->slug,
                'role' => $item->pivot->role,
            ]);

        $activeId = (int) $request->session()->get('active_workspace_id');
        $active = $workspaces->firstWhere('id', $activeId) ?? $workspaces->first();

        $members = [];
        $moduleCatalog = null;
        if ($active) {
            $request->session()->put('active_workspace_id', $active['id']);
            $activeModel = Workspace::query()->findOrFail($active['id']);
            $members = $activeModel->users()->orderBy('name')->get()->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->pivot->role,
                'enabled_modules' => $this->decodeModules($user->pivot->enabled_modules ?? null),
            ]);

            $global = $modules->globallyEnabledKeys();
            $workspaceKeys = $modules->workspaceEnabledKeys($activeModel);
            $moduleCatalog = [
                'keys' => NavModules::keys(),
                'workspace_modules' => $activeModel->enabled_modules,
                'items' => collect(NavModules::catalog())
                    ->map(fn (array $meta, string $key) => [
                        'key' => $key,
                        'label' => $meta['label'],
                        'tone' => $meta['tone'],
                        'globally_enabled' => in_array($key, $global, true),
                        'workspace_enabled' => in_array($key, $workspaceKeys, true),
                    ])
                    ->values()
                    ->all(),
            ];
        }

        $subscription = $billing->subscription($workspace);

        $category = $request->string('category')->toString();
        if ($category === '' || ! array_key_exists($category, IntegrationCatalog::categories())) {
            $category = 'social';
        }

        $configure = $request->string('configure')->toString();
        if ($configure !== '' && ! IntegrationCatalog::find($configure)) {
            $configure = '';
        }

        return Inertia::render('Settings/Index', [
            'tab' => $tab,
            'provider_category' => $category,
            'configure_provider' => $configure ?: null,
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'categories' => IntegrationCatalog::categories(),
            'integrations' => $integrations->dashboard($workspace),
            'workspaces' => $workspaces,
            'activeWorkspace' => $active,
            'members' => $members,
            'roles' => WorkspaceRole::values(),
            'moduleCatalog' => $moduleCatalog,
            'billing' => [
                'plan' => $subscription->plan,
                'seats' => (int) $subscription->seats,
                'status' => $subscription->status,
                'billing_currency' => $subscription->billing_currency,
                'mrr_amount' => $subscription->mrr_amount ?? $subscription->mrr_usd,
                'billing_interval' => $subscription->billing_interval ?? 'month',
            ],
            'account' => [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
        ]);
    }

    /**
     * @return list<string>|null
     */
    private function decodeModules(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? array_values($decoded) : [];
        }

        return is_array($value) ? array_values($value) : [];
    }
}
