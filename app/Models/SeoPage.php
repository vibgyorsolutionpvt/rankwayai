<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoPage extends Model
{
    protected $fillable = [
        'workspace_id',
        'seo_site_id',
        'url',
        'title',
        'meta_description',
        'h1',
        'canonical',
        'indexable',
        'has_schema',
        'images_missing_alt',
        'internal_links',
        'redirect_to',
        'word_count',
        'status_code',
        'audit_meta',
        'render_mode',
        'depth',
        'inlink_count',
        'outlink_count',
        'is_orphan',
        'load_time_ms',
    ];

    protected function casts(): array
    {
        return [
            'indexable' => 'boolean',
            'has_schema' => 'boolean',
            'is_orphan' => 'boolean',
            'audit_meta' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SeoSite::class, 'seo_site_id');
    }

    public function issues(): HasMany
    {
        return $this->hasMany(SeoIssue::class);
    }
}
