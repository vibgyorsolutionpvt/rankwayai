<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoSuggestion extends Model
{
    protected $fillable = [
        'workspace_id',
        'seo_site_id',
        'type',
        'title',
        'body',
        'status',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(SeoSite::class, 'seo_site_id');
    }
}
