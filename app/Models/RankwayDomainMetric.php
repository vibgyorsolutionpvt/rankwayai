<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RankwayDomainMetric extends Model
{
    protected $fillable = [
        'rankway_domain_id',
        'organic_traffic',
        'organic_keywords',
        'backlinks',
        'referring_domains',
        'authority_score',
        'visibility_score',
        'keyword_score',
        'backlink_score',
        'referring_score',
        'technical_score',
        'performance_score',
        'content_score',
        'growth_score',
        'probe',
        'breakdown',
        'provider',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'probe' => 'array',
            'breakdown' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(RankwayDomain::class, 'rankway_domain_id');
    }
}
