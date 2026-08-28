<?php

namespace App\Services\Billing;

use App\Models\BillingAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Models\WorkspaceSubscription;
use App\Services\Integrations\ProviderStatus;
use Illuminate\Support\Str;

class BillingService
{
    public function __construct(
        private RazorpayClient $razorpay,
        private BillingAccountService $accounts,
    ) {}

    public function razorpayConfigured(): bool
    {
        return $this->razorpay->configured();
    }

    public function stripeConfigured(): bool
    {
        return ProviderStatus::snapshot()['stripe'] ?? false;
    }

    public function account(User $user): BillingAccount
    {
        return $this->accounts->account($user);
    }

    public function accountForWorkspace(Workspace $workspace): ?BillingAccount
    {
        return $this->accounts->accountForWorkspace($workspace);
    }

    public function subscription(Workspace $workspace): WorkspaceSubscription
    {
        $account = $this->accounts->accountForWorkspace($workspace);
        if ($account && $this->accounts->workspaceIsCovered($workspace, $account)) {
            $defaults = WorkspaceSubscription::defaultsForPlan(
                $account->plan,
                $account->billing_market ?: PlanCatalog::MARKET_IN,
                $account->billing_interval ?: PlanCatalog::INTERVAL_MONTH,
            );

            return WorkspaceSubscription::query()->updateOrCreate(
                ['workspace_id' => $workspace->id],
                [
                    'plan' => $account->plan,
                    'status' => $account->status,
                    'billing_provider' => $account->billing_provider,
                    'billing_market' => $account->billing_market,
                    'billing_currency' => $account->billing_currency,
                    'billing_interval' => $account->billing_interval,
                    'seats' => $account->seats ?? $defaults['seats'],
                    'mrr_usd' => $account->mrr_usd,
                    'mrr_amount' => $account->mrr_amount,
                    'limits' => $account->limits ?? $defaults['limits'],
                    'trial_ends_at' => $account->trial_ends_at,
                    'current_period_ends_at' => $account->current_period_ends_at,
                    'razorpay_customer_id' => $account->razorpay_customer_id,
                    'razorpay_subscription_id' => $account->razorpay_subscription_id,
                    'razorpay_payment_link_id' => $account->razorpay_payment_link_id,
                ],
            );
        }

        return WorkspaceSubscription::query()->firstOrCreate(
            ['workspace_id' => $workspace->id],
            array_merge(
                [
                    'plan' => 'free',
                    'status' => 'active',
                    'billing_provider' => 'manual',
                    'billing_interval' => PlanCatalog::INTERVAL_MONTH,
                    'trial_ends_at' => null,
                    'current_period_ends_at' => null,
                ],
                WorkspaceSubscription::defaultsForPlan('free', PlanCatalog::MARKET_IN, PlanCatalog::INTERVAL_MONTH)
            )
        );
    }

    public function changePlan(
        Workspace $workspace,
        string $plan,
        string $status = 'active',
        string $market = PlanCatalog::MARKET_IN,
        string $provider = 'manual',
        string $interval = PlanCatalog::INTERVAL_MONTH
    ): WorkspaceSubscription {
        $owner = $this->accounts->primaryOwner($workspace);
        if (! $owner) {
            return $this->applyFreeWorkspaceSubscription($workspace, $market);
        }

        $account = $this->accounts->account($owner);
        $interval = $plan === 'free'
            ? PlanCatalog::INTERVAL_MONTH
            : PlanCatalog::normalizeInterval($interval);

        $defaults = WorkspaceSubscription::defaultsForPlan($plan, $market, $interval);

        $account->update([
            'plan' => $plan,
            'status' => $status,
            'billing_provider' => $provider,
            'billing_market' => $defaults['billing_market'],
            'billing_currency' => $defaults['billing_currency'],
            'billing_interval' => $defaults['billing_interval'],
            'seats' => $defaults['seats'],
            'mrr_usd' => $defaults['mrr_usd'],
            'mrr_amount' => $defaults['mrr_amount'],
            'limits' => $defaults['limits'],
            'trial_ends_at' => $status === 'trialing'
                ? ($account->trial_ends_at ?: now()->addDays(14))
                : null,
            'current_period_ends_at' => $plan === 'free'
                ? null
                : ($interval === PlanCatalog::INTERVAL_YEAR ? now()->addYear() : now()->addMonth()),
            'billing_workspace_id' => $workspace->id,
        ]);

        if ($plan === 'free') {
            $account->update([
                'razorpay_payment_link_id' => null,
                'spent_usd' => 0,
            ]);
        }

        $this->accounts->syncWorkspaceSubscriptions($account->fresh());

        return $this->subscription($workspace);
    }

    /**
     * @return array{ok:bool, message:string, checkout_url?:string}
     */
    public function startCheckout(
        Workspace $workspace,
        string $plan,
        string $market = PlanCatalog::MARKET_IN,
        string $interval = PlanCatalog::INTERVAL_MONTH,
        ?User $actor = null,
    ): array {
        $owner = $this->accounts->primaryOwner($workspace);
        if (! $owner) {
            return ['ok' => false, 'message' => 'No workspace owner for billing.'];
        }

        $account = $this->accounts->account($owner);
        $market = $market === PlanCatalog::MARKET_IN ? PlanCatalog::MARKET_IN : PlanCatalog::MARKET_GLOBAL;
        $interval = PlanCatalog::normalizeInterval($interval);

        if ($plan === 'free') {
            $this->changePlan($workspace, 'free', 'active', $market, 'manual', PlanCatalog::INTERVAL_MONTH);

            return [
                'ok' => true,
                'message' => 'Switched to Free plan.',
            ];
        }

        return $this->startRazorpayCheckout($account, $workspace, $plan, $market, $interval, $actor ?? $owner);
    }

    /**
     * @return array{ok:bool, message:string, checkout_url?:string}
     */
    private function startRazorpayCheckout(
        BillingAccount $account,
        Workspace $workspace,
        string $plan,
        string $market,
        string $interval,
        User $actor,
    ): array {
        if (! $this->razorpayConfigured()) {
            $this->changePlan($workspace, $plan, 'active', $market, 'manual', $interval);

            return [
                'ok' => true,
                'message' => 'Plan updated.',
            ];
        }

        $amount = (float) PlanCatalog::price($plan, $market, $interval);
        $currency = $market === PlanCatalog::MARKET_IN ? 'INR' : 'USD';

        $result = $this->razorpay->createPaymentLink([
            'link_id' => 'plan_'.$account->id.'_'.$plan.'_'.$interval.'_'.Str::lower(Str::random(8)),
            'amount' => $amount,
            'currency' => $currency,
            'purpose' => ucfirst($plan).' plan ('.$interval.') — account',
            'customer_id' => 'acct_'.$account->id,
            'customer_email' => $actor->email,
            'customer_phone' => null,
            'customer_name' => $actor->name,
            'return_url' => route('billing.index', [
                'checkout' => 'success',
                'market' => $market,
                'interval' => $interval,
            ]),
            'notes' => [
                'type' => 'plan_checkout',
                'billing_account_id' => (string) $account->id,
                'workspace_id' => (string) $workspace->id,
                'plan' => $plan,
                'market' => $market,
                'interval' => $interval,
            ],
        ]);

        if (! $result['ok']) {
            report(new \RuntimeException('Razorpay payment link failed: '.($result['error'] ?? 'unknown')));

            return [
                'ok' => false,
                'message' => $this->paymentStartErrorMessage($result['error'] ?? null),
            ];
        }

        $account->update([
            'billing_provider' => 'razorpay',
            'billing_market' => $market,
            'billing_currency' => $currency,
            'billing_interval' => $interval,
            'razorpay_payment_link_id' => $result['link_id'],
            'billing_workspace_id' => $workspace->id,
            'status' => 'pending',
        ]);

        $this->accounts->syncWorkspaceSubscriptions($account->fresh());

        return [
            'ok' => true,
            'message' => 'Redirecting to payment…',
            'checkout_url' => $result['link_url'],
        ];
    }

    public function applyCheckoutSuccess(
        Workspace $workspace,
        string $plan,
        string $market = PlanCatalog::MARKET_GLOBAL,
        string $provider = 'razorpay',
        ?string $customerId = null,
        ?string $paymentRef = null,
        string $interval = PlanCatalog::INTERVAL_MONTH,
        ?BillingAccount $account = null,
        ?User $actor = null,
        ?float $amount = null,
    ): WorkspaceSubscription {
        $owner = $this->accounts->primaryOwner($workspace);
        $account ??= $owner ? $this->accounts->account($owner) : null;

        if (! $account) {
            return $this->applyFreeWorkspaceSubscription($workspace, $market);
        }

        $interval = PlanCatalog::normalizeInterval($interval);
        $defaults = WorkspaceSubscription::defaultsForPlan($plan, $market, $interval);

        $account->update([
            'plan' => $plan,
            'status' => 'active',
            'billing_provider' => $provider,
            'billing_market' => $defaults['billing_market'],
            'billing_currency' => $defaults['billing_currency'],
            'billing_interval' => $defaults['billing_interval'],
            'seats' => $defaults['seats'],
            'mrr_usd' => $defaults['mrr_usd'],
            'mrr_amount' => $defaults['mrr_amount'],
            'limits' => $defaults['limits'],
            'trial_ends_at' => null,
            'current_period_ends_at' => $plan === 'free'
                ? null
                : ($interval === PlanCatalog::INTERVAL_YEAR ? now()->addYear() : now()->addMonth()),
            'billing_workspace_id' => $workspace->id,
            'razorpay_customer_id' => $customerId ?: $account->razorpay_customer_id,
            'razorpay_subscription_id' => $paymentRef ?: $account->razorpay_subscription_id,
            'razorpay_payment_link_id' => $paymentRef ?: $account->razorpay_payment_link_id,
        ]);

        $this->accounts->recordTransaction(
            $account->fresh(),
            'plan_checkout',
            $amount ?? (float) $defaults['mrr_amount'],
            (string) $defaults['billing_currency'],
            'paid',
            $provider,
            $paymentRef,
            $actor ?? $owner,
            $workspace,
            $plan,
            null,
            null,
        );

        $this->accounts->syncWorkspaceSubscriptions($account->fresh());

        return $this->subscription($workspace);
    }

    public function applyAccountCheckoutSuccess(
        BillingAccount $account,
        string $plan,
        string $market,
        string $provider,
        ?string $customerId,
        ?string $paymentRef,
        string $interval,
        ?Workspace $contextWorkspace = null,
        ?float $amount = null,
    ): BillingAccount {
        $workspace = $contextWorkspace
            ?? $account->billingWorkspace
            ?? Workspace::query()->find($this->accounts->coveredWorkspaceIds($account)[0] ?? 0);

        if ($workspace) {
            $this->applyCheckoutSuccess(
                $workspace,
                $plan,
                $market,
                $provider,
                $customerId,
                $paymentRef,
                $interval,
                $account,
                $account->user,
                $amount,
            );
        }

        return $account->fresh();
    }

    public function syncPendingRazorpayCheckout(?BillingAccount $account, ?string $linkId = null): bool
    {
        if (! $account || ! $this->razorpayConfigured()) {
            return false;
        }

        if ($account->status !== 'pending' || $account->billing_provider !== 'razorpay') {
            return false;
        }

        $ref = $linkId ?: $account->razorpay_payment_link_id;
        if (blank($ref)) {
            return false;
        }

        $link = $this->razorpay->getPaymentLink((string) $ref);
        if (! ($link['ok'] ?? false)) {
            return false;
        }

        if (($link['status'] ?? '') !== 'paid' && (float) ($link['amount_paid'] ?? 0) <= 0) {
            return false;
        }

        $notes = $link['raw']['notes'] ?? [];
        $plan = (string) ($notes['plan'] ?? $account->plan ?: 'starter');
        if ($plan === 'free' || $plan === 'pending') {
            $plan = 'starter';
        }
        $market = ($notes['market'] ?? $account->billing_market) === PlanCatalog::MARKET_GLOBAL
            ? PlanCatalog::MARKET_GLOBAL
            : PlanCatalog::MARKET_IN;
        $interval = PlanCatalog::normalizeInterval($notes['interval'] ?? $account->billing_interval);
        $workspaceId = (int) ($notes['workspace_id'] ?? $account->billing_workspace_id ?? 0);
        $workspace = $workspaceId ? Workspace::query()->find($workspaceId) : null;

        if ($workspace) {
            $this->applyCheckoutSuccess(
                $workspace,
                $plan,
                $market,
                'razorpay',
                null,
                (string) $ref,
                $interval,
                $account,
                $account->user,
                (float) ($link['amount_paid'] ?? 0),
            );

            return true;
        }

        $this->applyAccountCheckoutSuccess(
            $account,
            $plan,
            $market,
            'razorpay',
            null,
            (string) $ref,
            $interval,
            null,
            (float) ($link['amount_paid'] ?? 0),
        );

        return true;
    }

    public function cancel(Workspace $workspace): WorkspaceSubscription
    {
        $account = $this->accounts->accountForWorkspace($workspace);
        $market = $account?->billing_market ?: PlanCatalog::MARKET_IN;

        return $this->changePlan($workspace, 'free', 'active', $market, 'manual', PlanCatalog::INTERVAL_MONTH);
    }

    private function applyFreeWorkspaceSubscription(Workspace $workspace, string $market): WorkspaceSubscription
    {
        return WorkspaceSubscription::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            array_merge(
                WorkspaceSubscription::defaultsForPlan('free', $market, PlanCatalog::INTERVAL_MONTH),
                [
                    'plan' => 'free',
                    'status' => 'active',
                    'billing_provider' => 'manual',
                    'trial_ends_at' => null,
                    'current_period_ends_at' => null,
                ],
            ),
        );
    }

    private function paymentStartErrorMessage(?string $razorpayError): string
    {
        if (blank($razorpayError)) {
            return 'Couldn’t start payment. Please try again or contact support.';
        }

        $lower = strtolower($razorpayError);

        if (str_contains($lower, 'expired')) {
            return 'Razorpay API keys have expired. Generate new Test keys in Razorpay Dashboard → Account & Settings → API Keys, then update `.env`.';
        }

        if (str_contains($lower, 'authentication')) {
            return 'Razorpay authentication failed. Check `RAZORPAY_KEY_ID` and `RAZORPAY_KEY_SECRET` in `.env` match your Dashboard keys (Test mode).';
        }

        return 'Couldn’t start payment: '.$razorpayError;
    }
}
