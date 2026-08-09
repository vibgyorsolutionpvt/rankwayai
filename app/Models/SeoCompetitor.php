<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoCompetitor extends Model
{
    protected $fillable = [
        'workspace_id',
        'domain',
        'overlap_score',
        'shared_keywords',
    ];

    protected function casts(): array
    {
        return [
            'shared_keywords' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
