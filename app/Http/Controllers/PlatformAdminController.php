<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CreditRecharge;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\Services\Access\ModuleAccess;
use App\Services\Billing\BillingService;
use App\Services\Billing\PlanCatalog;
use App\Services\Workspaces\ProvisionClientWorkspace;
use App\Support\NavModules;
use App\Support\SocialPlatforms;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PlatformAdminController extends Controller
{
    public function dashboard(Request $request, ModuleAccess $modules): Response
    {
        return Inertia::render('Admin/Dashboard', [
            'stats' => $this->stats(),
            'menus' => $modules->platformMenuStates(),
            'socialPlatforms' => SocialPlatforms::platformStates(),
            'recentUsers' => User::query()
                ->orderByDesc('created_at')
                ->limit(8)
                ->get()
                ->map(fn (User $user) => $this->userPayload($user)),
            'recentWorkspaces' => Workspace::query()
                ->with('subscription')
                ->withCount('users')
                ->orderByDesc('created_at')
                ->limit(8)
                ->get()
                ->map(fn (Workspace $workspace) => $this->workspacePayload($workspace)),
        ]);
    }

    public function users(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $users = User::query()
            ->withCount('workspaces')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($builder) use ($q) {
                    $builder
                        ->where('name', 'like', '%'.$q.'%')
                        ->orWhere('email', 'like', '%'.$q.'%');
                });
            })
            ->orderByDesc('is_superadmin')
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (User $user) => $this->userPayload($user));

        return Inertia::render('Admin/Users', [
            'stats' => $this->stats(),
            'users' => $users,
            'filters' => ['q' => $q],
        ]);
    }

    public function storeUser(Request $request, ProvisionClientWorkspace $provision): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'password' => ['required', Password::defaults()],
            'workspace_name' => ['nullable', 'string', 'max:160'],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'email_verified_at' => now(),
            'is_superadmin' => false,
            'is_active' => true,
        ]);

        $provision->for($user, $data['workspace_name'] ?? null);

        return back()->with('success', 'User created with a workspace');
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        if ($request->exists('is_superadmin')) {
            return back()->with('error', 'Super admin access cannot be changed from the panel.');
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'password' => ['nullable', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('is_active', $data)) {
            if ($user->is_superadmin) {
                return back()->with('error', 'Super admin account cannot be disabled.');
            }
            if ($user->id === $request->user()->id) {
                return back()->with('error', 'You cannot disable your own account.');
            }
            $user->is_active = (bool) $data['is_active'];
        }

        if (! empty($data['name'])) {
            $user->name = $data['name'];
        }

        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        return back()->with('success', 'User updated');
    }

    public function workspaces(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $workspaces = Workspace::query()
            ->with('subscription')
            ->withCount('users')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($builder) use ($q) {
                    $builder
                        ->where('name', 'like', '%'.$q.'%')
                        ->orWhere('slug', 'like', '%'.$q.'%');
                });
            })
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Workspace $workspace) => $this->workspacePayload($workspace));

        return Inertia::render('Admin/Workspaces', [
            'stats' => $this->stats(),
            'workspaces' => $workspaces,
            'filters' => ['q' => $q],
            'plans' => PlanCatalog::planIds(),
            'intervals' => PlanCatalog::intervals(),
            'markets' => [PlanCatalog::MARKET_IN, PlanCatalog::MARKET_GLOBAL],
        ]);
    }

    public function updateWorkspace(
        Request $request,
        Workspace $workspace,
        BillingService $billing
    ): RedirectResponse {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'plan' => ['sometimes', 'string', Rule::in(PlanCatalog::planIds())],
            'status' => ['sometimes', 'string', Rule::in(['active', 'trialing', 'past_due', 'canceled'])],
            'billing_market' => ['sometimes', 'string', Rule::in([PlanCatalog::MARKET_IN, PlanCatalog::MARKET_GLOBAL])],
            'billing_interval' => ['sometimes', 'string', Rule::in(PlanCatalog::intervals())],
        ]);

        if (! empty($data['name']) && $data['name'] !== $workspace->name) {
            $workspace->update(['name' => $data['name']]);
        }

        if (isset($data['plan']) || isset($data['status']) || isset($data['billing_market']) || isset($data['billing_interval'])) {
            $sub = $billing->subscription($workspace);
            $plan = $data['plan'] ?? $sub->plan ?? 'free';
            $status = $data['status'] ?? $sub->status ?? 'active';
            $market = $data['billing_market'] ?? $sub->billing_market ?? PlanCatalog::MARKET_IN;
            $interval = $data['billing_interval'] ?? $sub->billing_interval ?? PlanCatalog::INTERVAL_MONTH;

            $billing->changePlan(
                $workspace,
                $plan,
                $status,
                $market,
                'manual',
                $interval
            );
        }

        return back()->with('success', 'Workspace updated');
    }

    public function enterWorkspace(Request $request, Workspace $workspace): RedirectResponse
    {
        $request->session()->put('impersonate_workspace_id', $workspace->id);
        $request->session()->put('active_workspace_id', $workspace->id);

        ActivityLog::record($workspace, $request->user(), 'admin.impersonate', [
            'workspace' => $workspace->name,
        ]);

        return redirect()
            ->route('today')
            ->with('success', 'Viewing workspace: '.$workspace->name);
    }

    public function leaveWorkspace(Request $request): RedirectResponse
    {
        $request->session()->forget('impersonate_workspace_id');

        return redirect()
            ->route('admin.workspaces')
            ->with('success', 'Back to platform admin');
    }

    public function billing(): Response
    {
        $subscriptions = WorkspaceSubscription::query()
            ->with('workspace:id,name,slug')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(fn (WorkspaceSubscription $sub) => [
                'id' => $sub->id,
                'workspace' => $sub->workspace?->name,
                'slug' => $sub->workspace?->slug,
                'plan' => $sub->plan,
                'status' => $sub->status,
                'billing_market' => $sub->billing_market,
                'billing_interval' => $sub->billing_interval,
                'billing_provider' => $sub->billing_provider,
                'mrr_amount' => $sub->mrr_amount,
                'billing_currency' => $sub->billing_currency,
                'current_period_ends_at' => $sub->current_period_ends_at?->toDateString(),
            ]);

        $recharges = CreditRecharge::query()
            ->with('workspace:id,name')
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (CreditRecharge $row) => [
                'id' => $row->id,
                'workspace' => $row->workspace?->name,
                'credits' => $row->credits,
                'amount' => $row->amount,
                'currency' => $row->currency,
                'status' => $row->status,
                'provider' => $row->provider,
                'created_at' => $row->created_at?->toDateTimeString(),
            ]);

        $planCounts = WorkspaceSubscription::query()
            ->select('plan', DB::raw('count(*) as total'))
            ->groupBy('plan')
            ->pluck('total', 'plan');

        return Inertia::render('Admin/Billing', [
            'stats' => [
                ...$this->stats(),
                'paid_workspaces' => WorkspaceSubscription::query()->where('plan', '!=', 'free')->count(),
                'pending_recharges' => CreditRecharge::query()->where('status', 'pending')->count(),
                'plan_counts' => $planCounts,
            ],
            'subscriptions' => $subscriptions,
            'recharges' => $recharges,
        ]);
    }

    public function activity(Request $request): Response
    {
        $q = trim((string) $request->query('q', ''));

        $logs = ActivityLog::query()
            ->with(['user:id,name,email', 'workspace:id,name,slug'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($builder) use ($q) {
                    $builder
                        ->where('action', 'like', '%'.$q.'%')
                        ->orWhereHas('user', fn ($u) => $u->where('email', 'like', '%'.$q.'%')->orWhere('name', 'like', '%'.$q.'%'))
                        ->orWhereHas('workspace', fn ($w) => $w->where('name', 'like', '%'.$q.'%'));
                });
            })
            ->orderByDesc('id')
            ->paginate(40)
            ->withQueryString()
            ->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'user' => $log->user?->name,
                'email' => $log->user?->email,
                'workspace' => $log->workspace?->name,
                'meta' => $log->meta,
                'created_at' => $log->created_at?->toDateTimeString(),
            ]);

        return Inertia::render('Admin/Activity', [
            'stats' => $this->stats(),
            'logs' => $logs,
            'filters' => ['q' => $q],
        ]);
    }

    public function system(): Response
    {
        return Inertia::render('Admin/System', [
            'stats' => $this->stats(),
            'settings' => [
                'contact_email' => PlatformSetting::getValue(
                    'contact_email',
                    (string) config('seo.marketing.contact_email', 'contact@rankwayai.com')
                ),
                'contact_phone' => PlatformSetting::getValue(
                    'contact_phone',
                    (string) config('seo.marketing.contact_phone', '+91 9889995999')
                ),
            ],
            'runtime' => [
                'app_name' => config('app.name'),
                'app_url' => config('app.url'),
                'app_env' => config('app.env'),
                'queue' => config('queue.default'),
                'mail_from' => config('mail.from.address'),
                'media_disk' => config('media.disk'),
                'public_url' => config('seo.marketing.public_url'),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
            ],
        ]);
    }

    public function updateSystem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'contact_email' => ['required', 'email', 'max:190'],
            'contact_phone' => ['required', 'string', 'max:40'],
        ]);

        PlatformSetting::setValue('contact_email', $data['contact_email']);
        PlatformSetting::setValue('contact_phone', $data['contact_phone']);

        return back()->with('success', 'System contact settings saved');
    }

    public function jobs(): Response
    {
        $pending = DB::table('jobs')->orderByDesc('id')->limit(50)->get()->map(fn ($job) => [
            'id' => $job->id,
            'queue' => $job->queue,
            'attempts' => $job->attempts,
            'available_at' => date('Y-m-d H:i:s', $job->available_at),
            'payload_summary' => $this->jobDisplayName($job->payload ?? ''),
        ]);

        $failed = DB::table('failed_jobs')->orderByDesc('id')->limit(50)->get()->map(fn ($job) => [
            'id' => $job->id,
            'uuid' => $job->uuid,
            'queue' => $job->queue,
            'failed_at' => $job->failed_at,
            'exception' => \Illuminate\Support\Str::limit((string) $job->exception, 280),
            'payload_summary' => $this->jobDisplayName($job->payload ?? ''),
        ]);

        return Inertia::render('Admin/Jobs', [
            'stats' => [
                ...$this->stats(),
                'pending_jobs' => DB::table('jobs')->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
            ],
            'pending' => $pending,
            'failed' => $failed,
        ]);
    }

    public function retryFailedJob(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return back()->with('success', 'Failed job queued for retry');
    }

    public function flushFailedJobs(): RedirectResponse
    {
        Artisan::call('queue:flush');

        return back()->with('success', 'Failed jobs cleared');
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

    public function updateSocialPlatform(Request $request, string $key): RedirectResponse
    {
        abort_unless(in_array($key, SocialPlatforms::keys(), true), 404);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        SocialPlatforms::setPlatformEnabled($key, (bool) $data['enabled']);

        $label = SocialPlatforms::catalog()[$key]['label'] ?? $key;

        return back()->with(
            'success',
            ($data['enabled'] ? 'Enabled' : 'Disabled').' '.$label.' for all clients'
        );
    }

    public function home(Request $request, ModuleAccess $modules, ProvisionClientWorkspace $provision): RedirectResponse
    {
        if ($request->user()?->is_superadmin) {
            if ($request->session()->get('impersonate_workspace_id')) {
                return redirect()->route('today');
            }

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

            // Legacy accounts created without a tenant — provision on first login home.
            if (! $workspace) {
                $workspace = $provision->for($user);
                $request->session()->put('active_workspace_id', $workspace->id);
            }
        }

        return redirect()->route($modules->firstAllowedRoute($user, $workspace));
    }

    /** @return array{users:int,clients:int,workspaces:int,superadmins:int,active_users?:int} */
    private function stats(): array
    {
        return [
            'users' => User::query()->count(),
            'clients' => User::query()->where('is_superadmin', false)->count(),
            'workspaces' => Workspace::query()->count(),
            'superadmins' => User::query()->where('is_superadmin', true)->count(),
            'active_users' => User::query()->where('is_active', true)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_superadmin' => (bool) $user->is_superadmin,
            'is_active' => (bool) ($user->is_active ?? true),
            'workspaces_count' => $user->workspaces_count ?? $user->workspaces()->count(),
            'email_verified_at' => $user->email_verified_at?->toDateString(),
            'created_at' => $user->created_at?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function workspacePayload(Workspace $workspace): array
    {
        $sub = $workspace->subscription;

        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'members_count' => $workspace->users_count ?? $workspace->users()->count(),
            'created_at' => $workspace->created_at?->toDateString(),
            'plan' => $sub?->plan ?? 'free',
            'status' => $sub?->status ?? 'active',
            'billing_market' => $sub?->billing_market ?? PlanCatalog::MARKET_IN,
            'billing_interval' => $sub?->billing_interval ?? PlanCatalog::INTERVAL_MONTH,
            'billing_provider' => $sub?->billing_provider ?? 'manual',
            'current_period_ends_at' => $sub?->current_period_ends_at?->toDateString(),
        ];
    }

    private function jobDisplayName(string $payload): string
    {
        $data = json_decode($payload, true);
        $command = $data['displayName']
            ?? $data['data']['commandName']
            ?? null;

        if (is_string($command) && $command !== '') {
            return class_basename($command);
        }

        return 'Job';
    }
}
