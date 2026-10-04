<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WhatsappConversation extends Model
{
    protected $fillable = [
        'workspace_id',
        'crm_lead_id',
        'assigned_user_id',
        'phone',
        'contact_name',
        'external_conversation_id',
        'status',
        'unread_count',
        'last_message_preview',
        'last_message_at',
        'window_expires_at',
        'opted_out_until',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'window_expires_at' => 'datetime',
            'opted_out_until' => 'datetime',
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

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class);
    }

    public static function seesAllFor(User $user, Workspace $workspace): bool
    {
        return (bool) $user->is_superadmin
            || ($workspace->roleFor($user)?->canManageMembers() ?? false);
    }

    /**
     * Owners/admins see every thread; other members see threads assigned to them or still unassigned.
     */
    public function scopeVisibleTo(Builder $query, User $user, Workspace $workspace): Builder
    {
        if (static::seesAllFor($user, $workspace)) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->where('assigned_user_id', $user->id)
            ->orWhereNull('assigned_user_id'));
    }

    public function isVisibleTo(User $user, Workspace $workspace): bool
    {
        return $this->assigned_user_id === null
            || $this->assigned_user_id === $user->id
            || static::seesAllFor($user, $workspace);
    }

    public function windowOpen(): bool
    {
        return $this->window_expires_at !== null && $this->window_expires_at->isFuture();
    }

    public function optedOut(): bool
    {
        return $this->opted_out_until !== null && $this->opted_out_until->isFuture();
    }

    public static function phoneIsOptedOut(int $workspaceId, string $phone): bool
    {
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        if ($digits === '') {
            return false;
        }

        return static::query()
            ->where('workspace_id', $workspaceId)
            ->where('phone', '+'.$digits)
            ->where('opted_out_until', '>', now())
            ->exists();
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
            'assigned_user_id' => $this->assigned_user_id,
            'assigned_user_name' => $this->assigned_user_id ? $this->assignee?->name : null,
            'status' => $this->status,
            'unread_count' => $this->unread_count,
            'last_message_preview' => $this->last_message_preview,
            'last_message_at' => $this->last_message_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
            'window_open' => $this->windowOpen(),
            'window_expires_at' => $this->window_expires_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
            'opted_out' => $this->optedOut(),
            'opted_out_until' => $this->optedOut()
                ? $this->opted_out_until?->timezone(config('app.timezone'))->format('d M Y, g:i A')
                : null,
        ];
    }
}
