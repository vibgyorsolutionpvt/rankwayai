<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditRecharge extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'pack_id',
        'credits',
        'amount',
        'currency',
        'billing_market',
        'status',
        'provider',
        'provider_ref',
    ];

    protected function casts(): array
    {
        return [
            'credits' => 'integer',
            'amount' => 'decimal:2',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
