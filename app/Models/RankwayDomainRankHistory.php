<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RankwayDomainRankHistory extends Model
{
    protected $table = 'rankway_domain_rank_history';

    protected $fillable = [
        'rankway_domain_id',
        'rankway_score',
        'global_rank',
        'country_rank',
        'category_rank',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'recorded_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(RankwayDomain::class, 'rankway_domain_id');
    }
}
