<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceSubscription extends Model
{
    protected $fillable = [
        'workspace_id',
        'plan',
        'status',
        'billing_provider',
        'billing_market',
        'billing_currency',
        'billing_interval',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_checkout_session_id',
        'razorpay_customer_id',
        'razorpay_subscription_id',
        'razorpay_payment_link_id',
        'cashfree_order_id',
        'cashfree_payment_link_id',
        'cashfree_customer_id',
        'seats',
        'mrr_usd',
        'mrr_amount',
        'trial_ends_at',
        'current_period_ends_at',
        'limits',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'mrr_usd' => 'decimal:2',
            'mrr_amount' => 'decimal:2',
            'limits' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public static function defaultsForPlan(
        string $plan,
        string $market = 'in',
        string $interval = 'month'
    ): array {
        $interval = \App\Services\Billing\PlanCatalog::normalizeInterval($interval);

        $base = match ($plan) {
            'free' => [
                'seats' => 1,
                'limits' => [
                    'workspaces' => 1,
                    'ai_budget_usd' => 0,
                    'channel_sends_month' => 0,
                    'ai' => false,
                    'api' => false,
                    // SEO toolkit on free; social/WhatsApp/AI/CMS stay paid.
                    'seo_audit' => true,
                    'seo_apis' => true,
                    'seo_metrics' => true,
                    'modules' => ['seo', 'billing', 'settings'],
                ],
            ],
            'growth' => [
                'seats' => 10,
                'limits' => [
                    'workspaces' => 5,
                    'ai_budget_usd' => 50,
                    'channel_sends_month' => 5000,
                    'ai' => true,
                    'api' => true,
                ],
            ],
            'agency' => [
                'seats' => 25,
                'limits' => [
                    'workspaces' => 50,
                    'ai_budget_usd' => 200,
                    'channel_sends_month' => 50000,
                    'ai' => true,
                    'api' => true,
                ],
            ],
            default => [
                'seats' => 3,
                'limits' => [
                    'workspaces' => 1,
                    'ai_budget_usd' => 20,
                    'channel_sends_month' => 500,
                    'ai' => true,
                    'api' => true,
                ],
            ],
        };

        $marketMeta = \App\Services\Billing\PlanCatalog::market($market);
        // Store the charged period amount; UI formats /mo or /yr from billing_interval.
        $charged = \App\Services\Billing\PlanCatalog::price($plan, $market, $interval);

        return array_merge($base, [
            'mrr_usd' => \App\Services\Billing\PlanCatalog::mrrUsd($plan, $interval),
            'mrr_amount' => $charged,
            'billing_market' => $market,
            'billing_currency' => $marketMeta['currency'],
            'billing_interval' => $plan === 'free'
                ? \App\Services\Billing\PlanCatalog::INTERVAL_MONTH
                : $interval,
        ]);
    }
}
