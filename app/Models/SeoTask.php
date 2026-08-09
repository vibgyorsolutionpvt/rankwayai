<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoTask extends Model
{
    protected $fillable = [
        'workspace_id',
        'seo_site_id',
        'seo_issue_id',
        'title',
        'description',
        'priority',
        'status',
        'due_on',
        'source',
        'ai_suggestion',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'ai_suggestion' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(SeoSite::class, 'seo_site_id');
    }
}
