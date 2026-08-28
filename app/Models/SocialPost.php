<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialPost extends Model
{
    protected $fillable = [
        'workspace_id',
        'created_by',
        'title',
        'body',
        'platforms',
        'media_asset_id',
        'brand_kit_id',
        'status',
        'scheduled_at',
        'published_at',
        'failure_reason',
        'permalinks',
        'publish_log',
        'poster_variants',
        'requires_approval',
        'approved_at',
        'approved_by',
    ];

    protected function casts(): array
    {
        return [
            'platforms' => 'array',
            'permalinks' => 'array',
            'publish_log' => 'array',
            'poster_variants' => 'array',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'approved_at' => 'datetime',
            'requires_approval' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function brandKit(): BelongsTo
    {
        return $this->belongsTo(BrandKit::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SocialPublishLog::class);
    }

    public function toLibraryArray(): array
    {
        $publisher = app(\App\Services\Social\SocialPublisherService::class);
        $analytics = app(\App\Services\Social\SocialPostAnalyticsService::class);

        $publishedLogs = SocialPublishLog::query()
            ->where('social_post_id', $this->id)
            ->where('status', 'published')
            ->orderByDesc('id')
            ->get()
            ->unique('platform');

        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'platforms' => $this->platforms ?? [],
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at?->toDateTimeString(),
            'scheduled_at_local' => $this->scheduled_at?->format('Y-m-d\TH:i'),
            'published_at' => $this->published_at?->toDateTimeString(),
            'failure_reason' => $this->failure_reason,
            'permalinks' => $this->permalinks ?? [],
            'poster_variants' => $this->poster_variants ?? [],
            'requires_approval' => $this->requires_approval,
            'approved_at' => $this->approved_at?->toDateTimeString(),
            'media_asset_id' => $this->media_asset_id,
            'brand_kit_id' => $this->brand_kit_id,
            'media_url' => $this->media?->url(),
            'has_attached_media' => $publisher->hasAttachedMedia($this),
            'has_public_image' => $publisher->hasPublicImage($this),
            'failed_platforms' => $publisher->failedPlatforms($this),
            'has_publish_failures' => $publisher->hasPublishFailures($this),
            'platform_statuses' => $publisher->platformStatuses($this),
            'engagement' => $analytics->aggregateLogs($publishedLogs),
            'calendar_day' => $this->scheduled_at?->toDateString()
                ?: $this->published_at?->toDateString()
                ?: $this->created_at?->toDateString(),
        ];
    }
}
