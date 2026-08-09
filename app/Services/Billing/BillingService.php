<?php

namespace App\Services\Billing;

use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Models\WorkspaceSubscription;
use App\Services\Integrations\ProviderStatus;
use Illuminate\Support\Str;

class BillingService
{
    public function __construct(private CashfreeClient $cashfree) {}

    public function cashfreeConfigured(): bool
    {
        return $this->cashfree->configured();
    }

    public function stripeConfigured(): bool
    {
        return ProviderStatus::snapshot()['stripe'] ?? false;
    }

    public function razorpayConfigured(): bool
    {
        return ProviderStatus::snapshot()['razorpay'] ?? false;
    }

    public function subscription(Workspace $workspace): WorkspaceSubscription
    {
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
        $interval = $plan === 'free'
            ? PlanCatalog::INTERVAL_MONTH
            : PlanCatalog::normalizeInterval($interval);

        $sub = $this->subscription($workspace);
        $defaults = WorkspaceSubscription::defaultsForPlan($plan, $market, $interval);

        $sub->update([
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
                ? ($sub->trial_ends_at ?: now()->addDays(14))
                : null,
            'current_period_ends_at' => $plan === 'free'
                ? null
                : ($interval === PlanCatalog::INTERVAL_YEAR ? now()->addYear() : now()->addMonth()),
        ]);

        $aiBudget = (float) ($defaults['limits']['ai_budget_usd'] ?? 0);
        $aiSettings = WorkspaceAiSetting::query()->firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'monthly_budget_usd' => max($aiBudget, 0),
                'template_first' => true,
                'tone' => 'mixed',
            ]
        );
        $aiSettings->update(['monthly_budget_usd' => max($aiBudget, 0)]);

        return $sub->fresh();
    }

    /**
     * @return array{ok:bool, message:string, checkout_url?:string}
     */
    public function startCheckout(
        Workspace $workspace,
        string $plan,
        string $market = PlanCatalog::MARKET_IN,
        string $interval = PlanCatalog::INTERVAL_MONTH
    ): array {
        $market = $market === PlanCatalog::MARKET_IN ? PlanCatalog::MARKET_IN : PlanCatalog::MARKET_GLOBAL;
        $interval = PlanCatalog::normalizeInterval($interval);

        if ($plan === 'free') {
            $this->changePlan($workspace, 'free', 'active', $market, 'manual', PlanCatalog::INTERVAL_MONTH);

            return [
                'ok' => true,
                'message' => 'Switched to Free plan.',
            ];
        }

        return $this->startCashfreeCheckout($workspace, $plan, $market, $interval);
    }

    /**
     * @return array{ok:bool, message:string, checkout_url?:string}
     */
    private function startCashfreeCheckout(
        Workspace $workspace,
        string $plan,
        string $market,
        string $interval
    ): array {
        if (! $this->cashfreeConfigured()) {
            $this->changePlan($workspace, $plan, 'active', $market, 'manual', $interval);

            return [
                'ok' => true,
                'message' => 'Plan updated.',
            ];
        }

        $amount = (float) PlanCatalog::price($plan, $market, $interval);
        $currency = $market === PlanCatalog::MARKET_IN ? 'INR' : 'USD';
        $linkId = 'plan_'.$workspace->id.'_'.$plan.'_'.$interval.'_'.Str::lower(Str::random(8));

        $owner = $workspace->users()->orderByPivot('role')->first()
            ?? $workspace->users()->first();

        $result = $this->cashfree->createPaymentLink([
            'link_id' => $linkId,
            'amount' => $amount,
            'currency' => $currency,
            'purpose' => ucfirst($plan).' plan ('.$interval.') — '.$workspace->name,
            'customer_id' => 'ws_'.$workspace->id,
            'customer_email' => $owner?->email,
            'customer_phone' => null,
            'customer_name' => $owner?->name,
            'return_url' => route('billing.index').'?checkout=success&market='.$market.'&interval='.$interval,
            'notes' => [
                'type' => 'plan_checkout',
                'workspace_id' => (string) $workspace->id,
                'plan' => $plan,
                'market' => $market,
                'interval' => $interval,
            ],
        ]);

        if (! $result['ok']) {
            return [
                'ok' => false,
                'message' => 'Couldn’t start payment. Please try again or contact support.',
            ];
        }

        $sub = $this->subscription($workspace);
        $sub->update([
            'billing_provider' => 'cashfree',
            'billing_market' => $market,
            'billing_currency' => $currency,
            'billing_interval' => $interval,
            'cashfree_payment_link_id' => $result['link_id'],
            'status' => 'pending',
        ]);

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
        string $provider = 'cashfree',
        ?string $customerId = null,
        ?string $subscriptionId = null,
        string $interval = PlanCatalog::INTERVAL_MONTH
    ): WorkspaceSubscription {
        $sub = $this->changePlan($workspace, $plan, 'active', $market, $provider, $interval);

        if ($provider === 'cashfree') {
            $sub->update([
                'cashfree_customer_id' => $customerId ?: $sub->cashfree_customer_id,
                'cashfree_order_id' => $subscriptionId ?: $sub->cashfree_order_id,
            ]);
        }

        if ($provider === 'stripe') {
            $sub->update([
                'stripe_customer_id' => $customerId ?: $sub->stripe_customer_id,
                'stripe_subscription_id' => $subscriptionId ?: $sub->stripe_subscription_id,
            ]);
        }

        if ($provider === 'razorpay') {
            $sub->update([
                'razorpay_customer_id' => $customerId ?: $sub->razorpay_customer_id,
                'razorpay_subscription_id' => $subscriptionId ?: $sub->razorpay_subscription_id,
            ]);
        }

        return $sub->fresh();
    }

    public function cancel(Workspace $workspace): WorkspaceSubscription
    {
        $sub = $this->subscription($workspace);
        $market = $sub->billing_market ?: PlanCatalog::MARKET_IN;

        return $this->changePlan($workspace, 'free', 'active', $market, 'manual', PlanCatalog::INTERVAL_MONTH);
    }
}
