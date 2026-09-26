<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class BusinessCard extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'created_by',
        'brand_kit_id',
        'title',
        'template_key',
        'status',
        'person_name',
        'person_title',
        'person_photo_path',
        'cover_image_path',
        'logo_path',
        'pronouns',
        'company_name',
        'tagline',
        'phone',
        'whatsapp',
        'email',
        'website',
        'address',
        'social_links',
        'share_token',
        'is_public',
        'views_count',
        'phone_clicks',
        'whatsapp_clicks',
        'email_clicks',
        'website_clicks',
    ];

    protected function casts(): array
    {
        return [
            'social_links' => 'array',
            'is_public' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (BusinessCard $card): void {
            if (blank($card->share_token)) {
                $card->share_token = Str::lower(Str::random(40));
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
        return $this->hasMany(BusinessCardEvent::class);
    }

    public function publicPath(): string
    {
        return '/c/'.$this->share_token;
    }

    public function publicUrl(): string
    {
        return url($this->publicPath());
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && $this->is_public;
    }

    /**
     * Reject stale numeric IDs left over from pre-UUID bookmarks/links.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        if (is_numeric($value)) {
            abort(404, 'This card link is outdated. Open Business Cards from the sidebar.');
        }

        return parent::resolveRouteBinding($value, $field);
    }

    public function resolveBrandKit(): ?BrandKit
    {
        return $this->brandKit
            ?? $this->workspace?->resolveBrandKit();
    }
}
