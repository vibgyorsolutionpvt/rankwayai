<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoApiUsageLog extends Model
{
    protected $fillable = [
        'workspace_id',
        'provider',
        'operation',
        'units',
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
}
