<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SocialPublishLog extends Model
{
    protected $fillable = [
        'workspace_id',
        'social_post_id',
        'platform',
        'status',
        'permalink',
        'external_post_id',
        'metrics',
        'metrics_synced_at',
        'error',
        'attempt',
    ];

    protected function casts(): array
    {
        return [
            'metrics' => 'array',
            'metrics_synced_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(SocialPost::class, 'social_post_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
