<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoLocalTarget extends Model
{
    protected $fillable = [
        'workspace_id', 'seo_site_id', 'keyword', 'location_name', 'business_name',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(SeoLocalSnapshot::class);
    }
}
