<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessCardEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'business_card_id',
        'workspace_id',
        'event',
        'ip_hash',
        'user_agent',
    ];

    public function card(): BelongsTo
    {
        return $this->belongsTo(BusinessCard::class, 'business_card_id');
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
