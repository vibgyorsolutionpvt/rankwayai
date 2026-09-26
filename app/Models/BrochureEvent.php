<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrochureEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'brochure_id',
        'workspace_id',
        'event',
        'ip_hash',
        'user_agent',
    ];

    public function brochure(): BelongsTo
    {
        return $this->belongsTo(Brochure::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
