<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiCompletion;
use App\Services\Ai\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI-compatible chat completions (Groq, Cerebras, Mistral, OpenRouter, OpenAI…).
 */
class OpenAiCompatibleProvider implements AiProvider
{
    public function __construct(private readonly string $id) {}

    public function name(): string
    {
        return $this->id;
    }

    public function configured(): bool
    {
        $cfg = config('ai.providers.'.$this->id, []);

        return filled($cfg['key'] ?? null);
    }

    public function complete(string $system, string $user, int $maxTokens = 600): AiCompletion
    {
        if (! $this->configured()) {
            return AiCompletion::failed($this->name(), strtoupper($this->id).' not configured');
        }

        $cfg = config('ai.providers.'.$this->id, []);
        $base = rtrim((string) ($cfg['base_url'] ?? ''), '/');
        $model = (string) ($cfg['model'] ?? '');
        $key = $cfg['key'] ?? null;
        $apiUrl = $base !== '' ? $base.'/chat/completions' : null;

        if ($base === '' || $model === '') {
            return AiCompletion::failed(
                $this->name(),
                'Missing base_url/model for '.$this->id,
                $apiUrl,
                null,
                null,
                null,
                $model !== '' ? $model : null,
            );
        }

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
            'max_tokens' => $maxTokens,
            'temperature' => 0.7,
        ];

        try {
            $request = Http::timeout(30)->acceptJson();
            if (filled($key)) {
                $request = $request->withToken($key);
            }

            // OpenRouter free-model hint
            if ($this->id === 'openrouter' && ! empty($cfg['http_referer'])) {
                $request = $request->withHeaders([
                    'HTTP-Referer' => $cfg['http_referer'],
                    'X-Title' => $cfg['app_name'] ?? 'rankwayAI',
                ]);
            }

            $response = $request->post($apiUrl, $payload);
            $json = $response->json();
            $status = $response->status();

            if (! $response->successful()) {
                return AiCompletion::failed(
                    $this->name(),
                    is_array($json) ? (string) (data_get($json, 'error.message') ?? ($this->id.' request failed')) : ($this->id.' request failed'),
                    $apiUrl,
                    $status,
                    $payload,
                    $json ?? $response->body(),
                    $model,
                );
            }

            $text = (string) data_get($json, 'choices.0.message.content', '');
            $tokens = (int) data_get($json, 'usage.total_tokens', 0);

            if (blank($text)) {
                return AiCompletion::failed(
                    $this->name(),
                    'Empty '.$this->id.' response',
                    $apiUrl,
                    $status,
                    $payload,
                    $json,
                    $model,
                );
            }

            return new AiCompletion(
                $text,
                $this->name(),
                $tokens,
                true,
                null,
                $apiUrl,
                $status,
                $payload,
                $json,
                $model,
            );
        } catch (\Throwable $e) {
            return AiCompletion::failed(
                $this->name(),
                $e->getMessage(),
                $apiUrl,
                null,
                $payload,
                null,
                $model,
            );
        }
    }
}
