<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoBlogShare extends Model
{
    protected $fillable = [
        'seo_blog_post_id',
        'workspace_id',
        'channel',
        'share_url',
        'status',
    ];

    public function post(): BelongsTo
    {
        return $this->belongsTo(SeoBlogPost::class, 'seo_blog_post_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
