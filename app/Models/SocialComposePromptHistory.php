<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialComposePromptHistory extends Model
{
    protected $fillable = [
        'workspace_id',
        'user_id',
        'prompt',
        'offer',
        'provider',
        'api_url',
        'model',
        'http_status',
        'tokens',
        'ok',
        'error',
        'request_payload',
        'response_payload',
        'response_text',
        'attempts',
        'draft',
    ];

    protected function casts(): array
    {
        return [
            'ok' => 'boolean',
            'tokens' => 'integer',
            'http_status' => 'integer',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'attempts' => 'array',
            'draft' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Store one compose attempt with full API meta (URL + request/response).
     *
     * @param  array{
     *   provider?:?string,
     *   api_url?:?string,
     *   model?:?string,
     *   http_status?:?int,
     *   tokens?:int,
     *   ok?:bool,
     *   error?:?string,
     *   request?:?array<string,mixed>,
     *   response?:mixed,
     *   response_text?:?string,
     *   attempts?:list<array<string,mixed>>,
     *   draft?:?array<string,mixed>
     * }  $api
     */
    public static function remember(
        Workspace $workspace,
        ?int $userId,
        string $prompt,
        string $offer = '',
        array $api = [],
    ): self {
        $prompt = trim($prompt);
        $offer = trim($offer);

        $response = $api['response'] ?? null;
        if (is_string($response)) {
            $decoded = json_decode($response, true);
            $responsePayload = is_array($decoded) ? $decoded : ['raw' => $response];
        } elseif (is_array($response)) {
            $responsePayload = $response;
        } elseif ($response === null) {
            $responsePayload = null;
        } else {
            $responsePayload = ['value' => $response];
        }

        return static::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $userId,
            'prompt' => $prompt,
            'offer' => $offer !== '' ? $offer : null,
            'provider' => $api['provider'] ?? null,
            'api_url' => isset($api['api_url']) ? mb_substr((string) $api['api_url'], 0, 500) : null,
            'model' => $api['model'] ?? null,
            'http_status' => $api['http_status'] ?? null,
            'tokens' => (int) ($api['tokens'] ?? 0),
            'ok' => (bool) ($api['ok'] ?? true),
            'error' => isset($api['error']) ? mb_substr((string) $api['error'], 0, 500) : null,
            'request_payload' => $api['request'] ?? null,
            'response_payload' => $responsePayload,
            'response_text' => $api['response_text'] ?? null,
            'attempts' => $api['attempts'] ?? null,
            'draft' => $api['draft'] ?? null,
        ]);
    }
}
