<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoLink extends Model
{
    protected $fillable = [
        'workspace_id', 'seo_site_id', 'from_page_id', 'to_page_id',
        'to_url', 'type', 'is_external',
    ];

    protected function casts(): array
    {
        return ['is_external' => 'boolean'];
    }

    public function fromPage(): BelongsTo
    {
        return $this->belongsTo(SeoPage::class, 'from_page_id');
    }

    public function toPage(): BelongsTo
    {
        return $this->belongsTo(SeoPage::class, 'to_page_id');
    }
}
