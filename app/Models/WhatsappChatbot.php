<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappChatbot extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id',
        'enabled',
        'mode',
        'fallback',
        'rules',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'rules' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
