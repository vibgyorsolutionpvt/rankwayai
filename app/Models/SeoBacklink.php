<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoBacklink extends Model
{
    protected $fillable = [
        'workspace_id', 'seo_site_id', 'source_url', 'source_domain', 'target_url',
        'anchor', 'dofollow', 'domain_rank', 'status', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'dofollow' => 'boolean',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SeoSite::class, 'seo_site_id');
    }
}
