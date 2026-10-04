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
        'components',
    ];

    protected function casts(): array
    {
        return [
            'components' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return array{id:int, name:string, channel:string, category:?string, language:?string, wa_status:string, subject:?string, body:string, components:array<string, mixed>}
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
            'components' => $this->clientComponents(),
        ];
    }

    /**
     * Do not expose private file paths or Meta upload handles to the browser.
     *
     * @return array<string, mixed>
     */
    private function clientComponents(): array
    {
        $components = $this->components ?? [];
        $header = $components['header'] ?? [];

        return [
            'header' => array_filter([
                'format' => $header['format'] ?? null,
                'text' => $header['text'] ?? null,
                'filename' => $header['filename'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
            'footer' => $components['footer'] ?? '',
            'buttons' => $components['buttons'] ?? [],
        ];
    }
}
