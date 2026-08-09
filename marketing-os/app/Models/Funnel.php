<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Funnel extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'status',
        'headline',
        'subheadline',
        'cta_label',
        'cta_url',
        'body_html',
        'primary_color',
        'views',
        'leads',
    ];

    protected static function booted(): void
    {
        static::creating(function (Funnel $funnel): void {
            if (blank($funnel->slug)) {
                $funnel->slug = Str::slug($funnel->name).'-'.Str::lower(Str::random(4));
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function funnelLeads(): HasMany
    {
        return $this->hasMany(FunnelLead::class);
    }

    public function publicPath(): string
    {
        return '/f/'.$this->slug;
    }
}
