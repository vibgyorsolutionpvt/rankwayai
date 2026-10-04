<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelCampaign extends Model
{
    protected $fillable = [
        'workspace_id',
        'created_by',
        'name',
        'channel',
        'whatsapp_template_id',
        'crm_lead_group_id',
        'subject',
        'body',
        'status',
        'scheduled_at',
        'sent_at',
        'recipient_count',
        'sent_count',
        'failed_count',
        'provider',
        'meta',
        'failure_reason',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function whatsappTemplate(): BelongsTo
    {
        return $this->belongsTo(ChannelMessageTemplate::class, 'whatsapp_template_id');
    }

    public function crmLeadGroup(): BelongsTo
    {
        return $this->belongsTo(CrmLeadGroup::class);
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(ChannelCampaignRecipient::class);
    }
}
