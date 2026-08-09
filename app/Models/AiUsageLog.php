<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageLog extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'action',
        'provider',
        'tokens',
        'cost_usd',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'cost_usd' => 'decimal:6',
            'meta' => 'array',
        ];
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
