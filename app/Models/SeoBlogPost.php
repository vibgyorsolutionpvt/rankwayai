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
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'last_shared_at' => 'datetime',
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
        ];
    }
}
