<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappConversation extends Model
{
    protected $fillable = [
        'workspace_id',
        'crm_lead_id',
        'phone',
        'contact_name',
        'external_conversation_id',
        'status',
        'unread_count',
        'last_message_preview',
        'last_message_at',
        'window_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'window_expires_at' => 'datetime',
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

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class);
    }

    public function windowOpen(): bool
    {
        return $this->window_expires_at !== null && $this->window_expires_at->isFuture();
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'contact_name' => $this->contact_name,
            'crm_lead_id' => $this->crm_lead_id,
            'status' => $this->status,
            'unread_count' => $this->unread_count,
            'last_message_preview' => $this->last_message_preview,
            'last_message_at' => $this->last_message_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
            'window_open' => $this->windowOpen(),
            'window_expires_at' => $this->window_expires_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
        ];
    }
}
