<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\CreditRecharge;
use App\Models\WorkspaceSubscription;
use App\Services\Billing\BillingService;
use App\Services\Billing\CreditPackCatalog;
use App\Services\Billing\CreditRechargeService;
use App\Services\Billing\IpCountryResolver;
use App\Services\Billing\PlanAccess;
use App\Services\Billing\PlanCatalog;
use App\Services\Billing\UsageMeterService;
use App\Services\Integrations\ProviderStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BillingController extends Controller
{
    use ResolvesWorkspace;

    public function index(Request $request, BillingService $billing, UsageMeterService $usage, PlanAccess $plans): Response
    {
        $workspace = $this->workspace($request);
        $subscription = $billing->subscription($workspace);
        $account = $plans->accountEntitlementForWorkspace($workspace);
        $isAdmin = (bool) $request->user()?->is_superadmin;
        $market = $this->resolveMarket($request, $subscription, $isAdmin);

        $cashfree = $billing->cashfreeConfigured();
        $gatewayReady = $cashfree;
        $interval = $this->resolveInterval($request, $subscription);
        $historyPeriod = $usage->normalizeHistoryPeriod(
            $request->string('history')->toString() ?: UsageMeterService::HISTORY_7D
        );
        $tab = $request->string('tab')->toString();
        if (! in_array($tab, ['plan', 'history'], true)) {
            $tab = 'plan';
        }

        return Inertia::render('Billing/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'subscription' => $subscription,
            'account_plan' => [
                'plan' => $account['plan'],
                'status' => $account['status'],
                'workspace_limit' => $account['limit'],
                'workspaces_used' => $account['used'],
                'covers_this_workspace' => in_array((int) $workspace->id, $account['covered_ids'], true),
            ],
            'market' => $market,
            'interval' => $interval,
            'tab' => $tab,
            'history_period' => $historyPeriod,
            'can_switch_market' => $isAdmin,
            'markets' => $isAdmin
                ? [
                    [
                        'id' => PlanCatalog::MARKET_IN,
                        'label' => 'India (₹)',
                        'ready' => $cashfree,
                    ],
                    [
                        'id' => PlanCatalog::MARKET_GLOBAL,
                        'label' => 'International ($)',
                        'ready' => $cashfree,
                    ],
                ]
                : [],
            'cashfree_configured' => $cashfree,
            'providers' => ProviderStatus::snapshot(),
            'plans' => PlanCatalog::plansForMarket($market, $interval),
            'credit_packs' => CreditPackCatalog::packsForMarket($market),
            'credit_history' => CreditRecharge::query()
                ->where('workspace_id', $workspace->id)
                ->latest()
                ->limit(20)
                ->get()
                ->map(fn (CreditRecharge $row) => [
                    'id' => $row->id,
                    'pack_id' => $row->pack_id,
                    'credits' => (int) $row->credits,
                    'amount' => (float) $row->amount,
                    'currency' => $row->currency,
                    'status' => $row->status,
                    'provider' => $row->provider,
                    'at' => $row->created_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
                ])
                ->all(),
            'usage' => $usage->forWorkspace($workspace, $subscription),
            'ai_history' => $usage->aiHistory($workspace, $historyPeriod),
            'note' => $market === PlanCatalog::MARKET_IN
                ? 'Pay in Indian Rupees via Cashfree. Choose monthly or yearly.'
                : 'Pay in US Dollars via Cashfree. Choose monthly or yearly.',
            'is_platform_admin' => $isAdmin,
            'admin_note' => $isAdmin ? $this->adminNote($gatewayReady) : null,
        ]);
    }

    public function recharge(Request $request, BillingService $billing, CreditRechargeService $recharges): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        $subscription = $billing->subscription($workspace);
        $isAdmin = (bool) $request->user()?->is_superadmin;

        $data = $request->validate([
            'pack' => ['required', 'string', 'max:40'],
            'market' => ['nullable', 'in:in,global'],
        ]);

        $market = $isAdmin && ! empty($data['market'])
            ? $data['market']
            : $this->resolveMarket($request, $subscription, false);

        $result = $recharges->start($workspace, $request->user(), $data['pack'], $market);

        if (! empty($result['checkout_url'])) {
            return redirect()->away($result['checkout_url']);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function updatePlan(Request $request, BillingService $billing): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        $subscription = $billing->subscription($workspace);
        $isAdmin = (bool) $request->user()?->is_superadmin;

        $data = $request->validate([
            'plan' => ['required', 'in:free,starter,growth,agency'],
            'market' => ['nullable', 'in:in,global'],
            'interval' => ['nullable', 'in:month,year'],
        ]);

        // Clients cannot pick the other currency — market is assigned, not chosen.
        $market = $isAdmin && ! empty($data['market'])
            ? $data['market']
            : $this->resolveMarket($request, $subscription, false);

        $interval = PlanCatalog::normalizeInterval($data['interval'] ?? PlanCatalog::INTERVAL_MONTH);

        $result = $billing->startCheckout($workspace, $data['plan'], $market, $interval);

        if (! empty($result['checkout_url'])) {
            return redirect()->away($result['checkout_url']);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function cancel(Request $request, BillingService $billing): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $billing->cancel($workspace);

        return back()->with('success', 'You’re on the Free plan now.');
    }

    private function resolveMarket(Request $request, WorkspaceSubscription $subscription, bool $isAdmin): string
    {
        // Already on a paid market — keep them there.
        if ($subscription->plan !== 'free' && filled($subscription->billing_market)) {
            $locked = $subscription->billing_market;
            if (in_array($locked, [PlanCatalog::MARKET_IN, PlanCatalog::MARKET_GLOBAL], true)) {
                if ($isAdmin && $request->filled('market')) {
                    $q = $request->query('market');
                    if (in_array($q, [PlanCatalog::MARKET_IN, PlanCatalog::MARKET_GLOBAL], true)) {
                        return $q;
                    }
                }

                return $locked;
            }
        }

        // Superadmin can preview either market.
        if ($isAdmin && $request->filled('market')) {
            $q = $request->query('market');
            if (in_array($q, [PlanCatalog::MARKET_IN, PlanCatalog::MARKET_GLOBAL], true)) {
                return $q;
            }
        }

        return app(IpCountryResolver::class)->marketFor($request);
    }

    private function resolveInterval(Request $request, WorkspaceSubscription $subscription): string
    {
        if ($request->filled('interval')) {
            return PlanCatalog::normalizeInterval((string) $request->query('interval'));
        }

        if ($subscription->plan !== 'free' && filled($subscription->billing_interval)) {
            return PlanCatalog::normalizeInterval($subscription->billing_interval);
        }

        return PlanCatalog::INTERVAL_MONTH;
    }

    private function adminNote(bool $ready): string
    {
        return $ready
            ? 'Admin: Cashfree configured for India (₹) and Global ($) checkout.'
            : 'Admin: Cashfree keys missing — plans/credits apply manually. Set CASHFREE_CLIENT_ID, CASHFREE_CLIENT_SECRET, CASHFREE_ENV (sandbox|production).';
    }
}
