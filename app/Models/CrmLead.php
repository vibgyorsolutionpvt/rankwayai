<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CrmLead extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'email',
        'phone',
        'company',
        'stage',
        'source',
        'value_cents',
        'notes',
        'last_contacted_at',
    ];

    protected function casts(): array
    {
        return [
            'last_contacted_at' => 'datetime',
            'value_cents' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(CrmLeadActivity::class)->latest();
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(CrmQuotation::class)->latest();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CrmLeadAttachment::class)->latest();
    }

    public function whatsappConversations(): HasMany
    {
        return $this->hasMany(WhatsappConversation::class)->orderByDesc('last_message_at');
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function logActivity(string $type, string $body, ?User $user = null, ?array $meta = null): CrmLeadActivity
    {
        return $this->activities()->create([
            'workspace_id' => $this->workspace_id,
            'user_id' => $user?->id,
            'type' => $type,
            'body' => $body,
            'meta' => $meta,
        ]);
    }

    public function destinationFor(string $channel): ?string
    {
        return match ($channel) {
            'whatsapp', 'rcs', 'sms' => $this->phone,
            'email' => $this->email,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toClientArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'stage' => $this->stage,
            'source' => $this->source,
            'value_cents' => $this->value_cents,
            'notes' => $this->notes,
            'last_contacted_at' => $this->last_contacted_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
            'created_at' => $this->created_at?->timezone(config('app.timezone'))->format('d M Y'),
        ];
    }
}
