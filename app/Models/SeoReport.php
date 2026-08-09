<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoReport extends Model
{
    protected $fillable = [
        'workspace_id',
        'seo_site_id',
        'period',
        'period_start',
        'period_end',
        'summary',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SeoSite::class, 'seo_site_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
