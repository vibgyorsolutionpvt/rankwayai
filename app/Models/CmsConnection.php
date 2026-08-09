<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CmsConnection extends Model
{
    protected $fillable = [
        'workspace_id', 'provider', 'label', 'base_url', 'credentials', 'status', 'last_tested_at',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'last_tested_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function drafts(): HasMany
    {
        return $this->hasMany(SeoContentDraft::class);
    }
}
