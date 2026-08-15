<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoBlogPost extends Model
{
    protected $fillable = [
        'seo_site_id',
        'url',
        'url_hash',
        'title',
        'excerpt',
        'published_at',
        'source',
        'share_count',
        'last_shared_at',
        'verba_published_at',
        'verba_published_url',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'last_shared_at' => 'datetime',
            'verba_published_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SeoSite::class, 'seo_site_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(SeoBlogShare::class);
    }

    public function toArrayForUi(): array
    {
        return [
            'id' => $this->id,
            'url' => $this->url,
            'title' => $this->title ?: $this->url,
            'excerpt' => $this->excerpt,
            'published_at' => $this->published_at?->toDateString(),
            'source' => $this->source,
            'share_count' => (int) $this->share_count,
            'last_shared_at' => $this->last_shared_at?->diffForHumans(),
            'verba_published' => $this->verba_published_at !== null,
            'verba_published_url' => $this->verba_published_url,
            'verba_published_at' => $this->verba_published_at?->diffForHumans(),
        ];
    }
}
