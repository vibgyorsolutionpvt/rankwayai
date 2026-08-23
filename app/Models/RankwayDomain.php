<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RankwayDomain extends Model
{
    protected $fillable = [
        'domain',
        'url',
        'title',
        'category',
        'country',
        'rankway_score',
        'global_rank',
        'country_rank',
        'category_rank',
        'status',
        'last_error',
        'last_analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'last_analyzed_at' => 'datetime',
        ];
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(RankwayDomainMetric::class);
    }

    public function latestMetric(): HasOne
    {
        return $this->hasOne(RankwayDomainMetric::class)->latestOfMany('recorded_at');
    }

    public function rankHistory(): HasMany
    {
        return $this->hasMany(RankwayDomainRankHistory::class);
    }

    public function isFresh(int $hours = 24): bool
    {
        return $this->status === 'ready'
            && $this->last_analyzed_at !== null
            && $this->last_analyzed_at->gt(now()->subHours($hours));
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(bool $unlocked = false): array
    {
        $indexed = static::query()->where('status', 'ready')->whereNotNull('rankway_score')->count();
        $minForRank = (int) config('seo.rankway.min_index_for_rank', 50);
        $rankPreview = $indexed < $minForRank;
        $metric = $this->latestMetric;
        $dataSource = $metric?->provider ?? 'probe';
        $hasVerifiedAuthority = $dataSource !== 'probe'
            || ($metric?->backlinks ?? null) !== null
            || $dataSource === 'benchmark';

        $betterThan = null;
        if ($indexed >= (int) config('seo.rankway.min_index_for_percentile', 50) && $this->rankway_score !== null) {
            $worse = static::query()
                ->whereNotNull('rankway_score')
                ->where('rankway_score', '<', $this->rankway_score)
                ->count();
            $betterThan = (int) round(($worse / max($indexed, 1)) * 100);
        }

        $displayGlobalRank = $rankPreview ? null : $this->global_rank;
        $displayCountryRank = $rankPreview ? null : $this->country_rank;

        $payload = [
            'id' => $this->id,
            'domain' => $this->domain,
            'url' => $this->url,
            'title' => $this->title,
            'status' => $this->status,
            'rankway_score' => $this->rankway_score,
            'global_rank' => $displayGlobalRank,
            'country_rank' => $displayCountryRank,
            'category_rank' => $rankPreview ? null : $this->category_rank,
            'rank_preview' => $rankPreview,
            'rank_preview_message' => $rankPreview
                ? 'Early Rankway index — only '.$indexed.' site(s) analyzed so far. Absolute rank unlocks after more sites join the index.'
                : null,
            'rank_among_indexed' => ($this->global_rank && $indexed > 0 && $rankPreview)
                ? '#'.$this->global_rank.' of '.$indexed.' analyzed'
                : null,
            'data_source' => $dataSource,
            'has_verified_authority' => $hasVerifiedAuthority,
            'country' => $this->country,
            'category' => $this->category,
            'last_analyzed_at' => $this->last_analyzed_at?->toDateTimeString(),
            'last_error' => $this->last_error,
            'indexed_count' => $indexed,
            'better_than_percent' => $betterThan,
            'disclaimer' => 'Rankway Rank is an estimated ranking among Rankway-indexed websites based on SEO and web visibility signals — not Google traffic rank or Alexa rank.',
            'scores' => [
                'seo' => $metric?->technical_score,
                'performance' => $metric?->performance_score,
                'authority' => $metric?->authority_score,
                'visibility' => $metric?->visibility_score,
            ],
            'unlocked' => $unlocked,
        ];

        $breakdown = is_array($metric?->breakdown) ? $metric->breakdown : [];

        if ($unlocked) {
            $payload['metrics'] = [
                'organic_traffic' => $metric?->organic_traffic,
                'organic_keywords' => $metric?->organic_keywords,
                'backlinks' => $metric?->backlinks,
                'referring_domains' => $metric?->referring_domains,
                'technical_score' => $metric?->technical_score,
                'performance_score' => $metric?->performance_score,
                'content_score' => $metric?->content_score,
                'growth_score' => $metric?->growth_score,
                'keyword_score' => $metric?->keyword_score,
                'backlink_score' => $metric?->backlink_score,
                'referring_score' => $metric?->referring_score,
                'visibility_score' => $metric?->visibility_score,
            ];
            $payload['breakdown'] = $breakdown;
            $payload['probe'] = $metric?->probe;
            $payload['history'] = $this->rankHistory()
                ->orderByDesc('recorded_at')
                ->limit(30)
                ->get()
                ->sortBy('recorded_at')
                ->values()
                ->map(fn (RankwayDomainRankHistory $h) => [
                    'rankway_score' => $h->rankway_score,
                    'global_rank' => $h->global_rank,
                    'country_rank' => $h->country_rank,
                    'recorded_at' => $h->recorded_at?->toDateString(),
                ])
                ->all();
        } else {
            $payload['metrics'] = [
                'organic_traffic' => null,
                'organic_keywords' => null,
                'backlinks' => null,
                'referring_domains' => null,
            ];
            $payload['locked'] = [
                'backlinks',
                'keyword_opportunities',
                'competitor_analysis',
                'ai_recommendations',
            ];
        }

        return $payload;
    }
}
