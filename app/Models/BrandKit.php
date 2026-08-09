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
        'primary_color',
        'secondary_color',
        'font_family',
        'website_url',
        'phone',
        'email',
        'social_links',
        'default_cta_label',
        'default_cta_url',
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
}
