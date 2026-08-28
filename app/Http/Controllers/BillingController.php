<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\BillingTransaction;
use App\Models\CreditRecharge;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\Services\Billing\BillingAccountService;
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
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BillingController extends Controller
{
    use ResolvesWorkspace;

    public function index(
        Request $request,
        BillingService $billing,
        UsageMeterService $usage,
        PlanAccess $plans,
        CreditRechargeService $recharges,
        BillingAccountService $accounts,
        \App\Services\Billing\CreditWalletService $wallet
    ): Response {
        $workspace = $this->workspace($request);
        $user = $request->user();
        $billingAccount = $billing->account($user);
        $subscription = $billing->subscription($workspace);
        $account = $plans->accountEntitlementForWorkspace($workspace);
        $isAdmin = (bool) $user?->is_superadmin;
        $market = $this->resolveMarket($request, $subscription, $billingAccount, $isAdmin);

        $linkId = $request->string('link_id')->toString() ?: null;
        $syncedCredits = $recharges->syncPendingRazorpay($workspace, $billingAccount, $linkId);
        $syncedPlan = $billing->syncPendingRazorpayCheckout($billingAccount, $linkId);
        if ($syncedCredits !== [] || $syncedPlan) {
            $subscription = $billing->subscription($workspace);
            $billingAccount = $billingAccount->fresh();
            $totalCredits = collect($syncedCredits)->sum(fn ($row) => (int) $row->credits);
            if ($totalCredits > 0) {
                session()->flash('success', $totalCredits.' credits added to your wallet.');
            } elseif ($syncedPlan) {
                session()->flash('success', 'Payment received. Your plan is active.');
            }
        }

        $razorpay = $billing->razorpayConfigured();
        $gatewayReady = $razorpay;
        $interval = $this->resolveInterval($request, $subscription, $billingAccount);
        $historyPeriod = $usage->normalizeHistoryPeriod(
            $request->string('history')->toString() ?: UsageMeterService::HISTORY_7D
        );
        $tab = $request->string('tab')->toString();
        if (! in_array($tab, ['plan', 'history'], true)) {
            $tab = 'plan';
        }

        $ownedIds = $accounts->ownedWorkspaceIds($user);
        $historyWorkspaceFilter = $request->filled('workspace_filter')
            ? $request->integer('workspace_filter')
            : null;
        if ($historyWorkspaceFilter && ! in_array($historyWorkspaceFilter, $ownedIds, true)) {
            $historyWorkspaceFilter = null;
        }

        $ownedWorkspaces = Workspace::query()
            ->whereIn('id', $ownedIds)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (Workspace $w) => ['id' => $w->id, 'name' => $w->name])
            ->values()
            ->all();

        $creditSnap = $wallet->snapshot($workspace, $subscription);

        return Inertia::render('Billing/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'billing_account' => [
                'plan' => $billingAccount->plan,
                'status' => $billingAccount->status,
                'billing_market' => $billingAccount->billing_market,
                'billing_currency' => $billingAccount->billing_currency,
                'billing_interval' => $billingAccount->billing_interval,
                'mrr_amount' => (float) $billingAccount->mrr_amount,
                'mrr_usd' => (float) $billingAccount->mrr_usd,
                'topup_credits' => (int) $billingAccount->topup_credits,
            ],
            'subscription' => $subscription,
            'account_plan' => [
                'plan' => $account['plan'],
                'status' => $account['status'],
                'workspace_limit' => $account['limit'],
                'workspaces_used' => $account['used'],
                'covers_this_workspace' => in_array((int) $workspace->id, $account['covered_ids'], true),
            ],
            'owned_workspaces' => $ownedWorkspaces,
            'history_workspace_filter' => $historyWorkspaceFilter,
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
                        'ready' => $razorpay,
                    ],
                    [
                        'id' => PlanCatalog::MARKET_GLOBAL,
                        'label' => 'International ($)',
                        'ready' => $razorpay,
                    ],
                ]
                : [],
            'razorpay_configured' => $razorpay,
            'providers' => ProviderStatus::snapshot(),
            'plans' => PlanCatalog::plansForMarket($market, $interval),
            'credit_packs' => CreditPackCatalog::packsForMarket($market),
            'credit_history' => $wallet->rechargeHistory($workspace)
                ->map(fn (CreditRecharge $row) => [
                    'id' => $row->id,
                    'pack_id' => $row->pack_id,
                    'credits' => (int) $row->credits,
                    'amount' => (float) $row->amount,
                    'currency' => $row->currency,
                    'status' => $row->status,
                    'provider' => $row->provider,
                    'workspace' => $row->workspace?->name,
                    'at' => $row->created_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
                ])
                ->values()
                ->all(),
            'payment_history' => $accounts->paymentHistory($billingAccount)
                ->map(fn (BillingTransaction $row) => [
                    'id' => $row->id,
                    'type' => $row->type,
                    'plan' => $row->plan,
                    'pack_id' => $row->pack_id,
                    'credits' => $row->credits,
                    'amount' => (float) $row->amount,
                    'currency' => $row->currency,
                    'status' => $row->status,
                    'provider' => $row->provider,
                    'workspace' => $row->workspace?->name,
                    'at' => $row->created_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
                ])
                ->values()
                ->all(),
            'credits_shared' => ($creditSnap['source'] ?? '') === 'account',
            'usage' => $usage->forWorkspace($workspace, $subscription),
            'ai_history' => $usage->aiHistoryForAccount($user, $historyPeriod, $historyWorkspaceFilter),
            'note' => $market === PlanCatalog::MARKET_IN
                ? 'Pay in Indian Rupees via Razorpay. Choose monthly or yearly.'
                : 'Pay in US Dollars via Razorpay. Choose monthly or yearly.',
            'is_platform_admin' => $isAdmin,
            'admin_note' => $isAdmin ? $this->adminNote($gatewayReady) : null,
            'local_checkout_hint' => ! (app(\App\Services\Billing\RazorpayClient::class)->appUrlIsPublic())
                && $razorpay
                ? 'Local test: after Razorpay shows Payment Success, open Billing again — credits/plan sync automatically (webhooks can’t reach localhost).'
                : null,
        ]);
    }

    public function recharge(Request $request, BillingService $billing, CreditRechargeService $recharges): RedirectResponse|SymfonyResponse
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
            : $this->resolveMarket($request, $subscription, $billing->account($request->user()), false);

        $result = $recharges->start($workspace, $request->user(), $data['pack'], $market);

        if (! empty($result['checkout_url'])) {
            return Inertia::location($result['checkout_url']);
        }

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function updatePlan(Request $request, BillingService $billing): RedirectResponse|SymfonyResponse
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

        $market = $isAdmin && ! empty($data['market'])
            ? $data['market']
            : $this->resolveMarket($request, $subscription, $billing->account($request->user()), false);

        $interval = PlanCatalog::normalizeInterval($data['interval'] ?? PlanCatalog::INTERVAL_MONTH);

        $result = $billing->startCheckout($workspace, $data['plan'], $market, $interval, $request->user());

        if (! empty($result['checkout_url'])) {
            return Inertia::location($result['checkout_url']);
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

    private function resolveMarket(
        Request $request,
        WorkspaceSubscription $subscription,
        \App\Models\BillingAccount $billingAccount,
        bool $isAdmin
    ): string {
        if ($billingAccount->plan !== 'free' && filled($billingAccount->billing_market)) {
            $locked = $billingAccount->billing_market;
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

        if ($isAdmin && $request->filled('market')) {
            $q = $request->query('market');
            if (in_array($q, [PlanCatalog::MARKET_IN, PlanCatalog::MARKET_GLOBAL], true)) {
                return $q;
            }
        }

        return app(IpCountryResolver::class)->marketFor($request);
    }

    private function resolveInterval(
        Request $request,
        WorkspaceSubscription $subscription,
        \App\Models\BillingAccount $billingAccount
    ): string {
        if ($request->filled('interval')) {
            return PlanCatalog::normalizeInterval((string) $request->query('interval'));
        }

        if ($billingAccount->plan !== 'free' && filled($billingAccount->billing_interval)) {
            return PlanCatalog::normalizeInterval($billingAccount->billing_interval);
        }

        if ($subscription->plan !== 'free' && filled($subscription->billing_interval)) {
            return PlanCatalog::normalizeInterval($subscription->billing_interval);
        }

        return PlanCatalog::INTERVAL_MONTH;
    }

    private function adminNote(bool $ready): string
    {
        return $ready
            ? 'Admin: Razorpay configured for India (₹) and Global ($) checkout.'
            : 'Admin: Razorpay keys missing — plans/credits apply manually. Set RAZORPAY_KEY_ID and RAZORPAY_KEY_SECRET.';
    }
}
