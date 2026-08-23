<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoSite extends Model
{
    protected $fillable = [
        'workspace_id',
        'domain',
        'sitemap_url',
        'blog_feed_url',
        'blog_posts_synced_at',
        'status',
        'gsc_connected',
        'gsc_connection_mode',
        'gsc_property',
        'ga4_property',
        'gsc_token',
        'gsc_synced_at',
        'gsc_summary',
        'gsc_queries',
        'gsc_last_error',
        'crawl_frequency',
        'next_crawl_at',
        'crawl_status',
        'last_crawl_error',
        'last_crawled_at',
        'cwv_lcp',
        'cwv_cls',
        'cwv_inp',
        'pagespeed_score',
        'pagespeed_strategy',
        'pagespeed_checked_at',
        'pagespeed_error',
        'pagespeed_issues',
        'pagespeed_report',
        'crawl_mode',
        'backlinks',
        'referring_domains',
        'dofollow_backlinks',
        'backlinks_synced_at',
        'backlinks_provider',
        'backlink_summary',
        'rankway_domain_id',
    ];

    protected function casts(): array
    {
        return [
            'gsc_connected' => 'boolean',
            'gsc_token' => 'encrypted',
            'gsc_synced_at' => 'datetime',
            'gsc_summary' => 'array',
            'gsc_queries' => 'array',
            'last_crawled_at' => 'datetime',
            'next_crawl_at' => 'datetime',
            'pagespeed_checked_at' => 'datetime',
            'pagespeed_issues' => 'array',
            'pagespeed_report' => 'array',
            'backlinks_synced_at' => 'datetime',
            'blog_posts_synced_at' => 'datetime',
            'backlink_summary' => 'array',
            'cwv_lcp' => 'decimal:2',
            'cwv_cls' => 'decimal:3',
            'cwv_inp' => 'decimal:2',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function pages(): HasMany
    {
        return $this->hasMany(SeoPage::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(SeoIssue::class);
    }

    public function inboundBacklinks(): HasMany
    {
        return $this->hasMany(SeoBacklink::class);
    }

    public function blogPosts(): HasMany
    {
        return $this->hasMany(SeoBlogPost::class);
    }

    public function rankwayDomain(): BelongsTo
    {
        return $this->belongsTo(RankwayDomain::class, 'rankway_domain_id');
    }

    public function markGscConnected(?string $property = null, string $mode = 'oauth'): void
    {
        $this->update([
            'gsc_connected' => true,
            'gsc_connection_mode' => $mode,
            'gsc_property' => $property ?: 'sc-domain:'.$this->domain,
            'gsc_token' => $mode === 'oauth' ? $this->gsc_token : 'stub_gsc_'.bin2hex(random_bytes(12)),
            'status' => 'connected',
        ]);
    }
}
