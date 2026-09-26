<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandKit extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'is_active',
        'logo_path',
        'secondary_logo_path',
        'favicon_path',
        'primary_color',
        'secondary_color',
        'accent_color',
        'font_family',
        'heading_font',
        'brand_tone',
        'website_url',
        'phone',
        'email',
        'social_links',
        'default_cta_label',
        'default_cta_url',
        'default_header',
        'default_footer',
    ];

    protected function casts(): array
    {
        return [
            'social_links' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function activate(): void
    {
        static::query()
            ->where('workspace_id', $this->workspace_id)
            ->where('id', '!=', $this->id)
            ->update(['is_active' => false]);

        $this->update(['is_active' => true]);
    }

    public function headingFont(): string
    {
        $heading = trim((string) ($this->heading_font ?? ''));

        return $heading !== '' ? $heading : (string) ($this->font_family ?: 'Plus Jakarta Sans');
    }

    public function bodyFont(): string
    {
        return (string) ($this->font_family ?: 'Plus Jakarta Sans');
    }

    public function accentColor(): string
    {
        $accent = trim((string) ($this->accent_color ?? ''));

        return $accent !== '' ? $accent : (string) ($this->primary_color ?: '#0E9F90');
    }

    /**
     * Style tokens for documents / cards / PDF.
     *
     * @return array<string, mixed>
     */
    public function styleTokens(): array
    {
        return [
            'primary_color' => $this->primary_color ?: '#0E9F90',
            'secondary_color' => $this->secondary_color ?: '#0B1220',
            'accent_color' => $this->accentColor(),
            'heading_font' => $this->headingFont(),
            'body_font' => $this->bodyFont(),
            'brand_tone' => $this->brand_tone,
            'default_header' => $this->default_header,
            'default_footer' => $this->default_footer,
            'default_cta_label' => $this->default_cta_label,
            'default_cta_url' => $this->default_cta_url,
        ];
    }
}
