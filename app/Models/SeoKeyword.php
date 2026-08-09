<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoKeyword extends Model
{
    protected $fillable = [
        'workspace_id',
        'keyword',
        'group_name',
        'is_local',
        'location',
        'search_volume',
        'keyword_difficulty',
        'cpc',
        'competition',
        'metrics_provider',
        'metrics_fetched_at',
        'position',
        'position_change',
        'local_pack_position',
        'rank_provider',
        'last_checked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_local' => 'boolean',
            'cpc' => 'decimal:4',
            'competition' => 'decimal:4',
            'metrics_fetched_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function ranks(): HasMany
    {
        return $this->hasMany(SeoKeywordRank::class);
    }
}
