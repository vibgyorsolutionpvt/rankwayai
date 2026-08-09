<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoContentDraft extends Model
{
    protected $fillable = [
        'workspace_id', 'seo_keyword_id', 'cms_connection_id', 'title', 'slug', 'body_html',
        'meta_title', 'meta_description', 'status', 'external_id', 'published_url',
        'last_error', 'published_at',
    ];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CmsConnection::class, 'cms_connection_id');
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(SeoKeyword::class, 'seo_keyword_id');
    }
}
