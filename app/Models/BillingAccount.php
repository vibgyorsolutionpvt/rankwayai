<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingAccount extends Model
{
    protected $fillable = [
        'user_id',
        'billing_workspace_id',
        'plan',
        'status',
        'billing_provider',
        'billing_market',
        'billing_currency',
        'billing_interval',
        'seats',
        'mrr_usd',
        'mrr_amount',
        'spent_usd',
        'topup_credits',
        'trial_ends_at',
        'current_period_ends_at',
        'limits',
        'razorpay_customer_id',
        'razorpay_subscription_id',
        'razorpay_payment_link_id',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'mrr_usd' => 'decimal:2',
            'mrr_amount' => 'decimal:2',
            'spent_usd' => 'decimal:4',
            'topup_credits' => 'integer',
            'limits' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function billingWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'billing_workspace_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BillingTransaction::class);
    }

    public function creditRecharges(): HasMany
    {
        return $this->hasMany(CreditRecharge::class);
    }
}
