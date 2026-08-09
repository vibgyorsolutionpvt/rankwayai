<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappMessage extends Model
{
    protected $fillable = [
        'whatsapp_conversation_id',
        'user_id',
        'direction',
        'body',
        'status',
        'provider_message_id',
        'template_name',
        'meta',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsappConversation::class, 'whatsapp_conversation_id');
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
            'direction' => $this->direction,
            'body' => $this->body,
            'status' => $this->status,
            'template_name' => $this->template_name,
            'error_message' => $this->error_message,
            'user_id' => $this->user_id,
            'sent_at' => ($this->sent_at ?? $this->created_at)?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
        ];
    }
}
