<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoLocalSnapshot extends Model
{
    protected $fillable = [
        'workspace_id', 'seo_local_target_id', 'our_rank', 'pack_json', 'checked_at', 'provider',
    ];

    protected function casts(): array
    {
        return [
            'pack_json' => 'array',
            'checked_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(SeoLocalTarget::class, 'seo_local_target_id');
    }
}
