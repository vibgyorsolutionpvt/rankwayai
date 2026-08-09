<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelMessageTemplate extends Model
{
    protected $fillable = [
        'workspace_id',
        'created_by',
        'name',
        'channel',
        'category',
        'language',
        'wa_status',
        'subject',
        'body',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return array{id:int, name:string, channel:string, category:?string, language:?string, wa_status:string, subject:?string, body:string}
     */
    public function toArrayBrief(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'channel' => $this->channel,
            'category' => $this->category,
            'language' => $this->language,
            'wa_status' => $this->wa_status ?? 'draft',
            'subject' => $this->subject,
            'body' => $this->body,
        ];
    }
}
