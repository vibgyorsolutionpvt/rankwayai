<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingTransaction extends Model
{
    protected $fillable = [
        'billing_account_id',
        'user_id',
        'workspace_id',
        'type',
        'plan',
        'pack_id',
        'credits',
        'amount',
        'currency',
        'status',
        'provider',
        'provider_ref',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'amount' => 'decimal:2',
            'meta' => 'array',
        ];
    }

    public function billingAccount(): BelongsTo
    {
        return $this->belongsTo(BillingAccount::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
