<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CrmLeadActivity extends Model
{
    protected $fillable = [
        'workspace_id',
        'crm_lead_id',
        'user_id',
        'type',
        'body',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'crm_lead_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'body' => $this->body,
            'meta' => $this->meta ?? [],
            'user_name' => $this->user?->name,
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
            'created_at_iso' => $this->created_at?->toIso8601String(),
        ];
    }
}
