<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Brochure extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'created_by',
        'brand_kit_id',
        'title',
        'template_key',
        'status',
        'headline',
        'subheadline',
        'sections',
        'share_token',
        'is_public',
        'views_count',
        'downloads_count',
        'cta_clicks',
    ];

    protected function casts(): array
    {
        return [
            'sections' => 'array',
            'is_public' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Brochure $brochure): void {
            if (blank($brochure->share_token)) {
                $brochure->share_token = Str::lower(Str::random(40));
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function brandKit(): BelongsTo
    {
        return $this->belongsTo(BrandKit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BrochureEvent::class);
    }

    public function publicPath(): string
    {
        return '/b/'.$this->share_token;
    }

    public function publicUrl(): string
    {
        return url($this->publicPath());
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && $this->is_public;
    }

    public function resolveBrandKit(): ?BrandKit
    {
        return $this->brandKit
            ?? $this->workspace?->resolveBrandKit();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabledSections(): array
    {
        return collect($this->sections ?? [])
            ->filter(fn ($section) => is_array($section) && ($section['enabled'] ?? true))
            ->values()
            ->all();
    }
}
