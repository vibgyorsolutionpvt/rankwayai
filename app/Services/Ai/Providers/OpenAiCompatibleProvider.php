<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiCompletion;
use App\Services\Ai\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI-compatible chat completions (Groq, Cerebras, Mistral, OpenRouter, Ollama, OpenAI…).
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

        // Ollama only when explicitly enabled (avoids always-on localhost probes).
        if ($this->id === 'ollama') {
            return filter_var($cfg['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

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

        if ($base === '' || $model === '') {
            return AiCompletion::failed($this->name(), 'Missing base_url/model for '.$this->id);
        }

        try {
            $request = Http::timeout(30)->acceptJson();
            if (filled($key)) {
                $request = $request->withToken($key);
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

            // OpenRouter free-model hint
            if ($this->id === 'openrouter' && ! empty($cfg['http_referer'])) {
                $request = $request->withHeaders([
                    'HTTP-Referer' => $cfg['http_referer'],
                    'X-Title' => $cfg['app_name'] ?? 'rankwayAI',
                ]);
            }

            $response = $request->post($base.'/chat/completions', $payload);

            if (! $response->successful()) {
                return AiCompletion::failed(
                    $this->name(),
                    $response->json('error.message') ?? ($this->id.' request failed')
                );
            }

            $text = (string) data_get($response->json(), 'choices.0.message.content', '');
            $tokens = (int) data_get($response->json(), 'usage.total_tokens', 0);

            if (blank($text)) {
                return AiCompletion::failed($this->name(), 'Empty '.$this->id.' response');
            }

            return new AiCompletion($text, $this->name(), $tokens);
        } catch (\Throwable $e) {
            return AiCompletion::failed($this->name(), $e->getMessage());
        }
    }
}
