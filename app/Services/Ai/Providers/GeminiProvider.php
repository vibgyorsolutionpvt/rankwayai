<?php

namespace App\Services\Ai\Providers;

use App\Services\Ai\AiCompletion;
use App\Services\Ai\Contracts\AiProvider;
use Illuminate\Support\Facades\Http;

class GeminiProvider implements AiProvider
{
    public function name(): string
    {
        return 'gemini';
    }

    public function configured(): bool
    {
        return filled(config('ai.providers.gemini.key'));
    }

    public function complete(string $system, string $user, int $maxTokens = 600): AiCompletion
    {
        if (! $this->configured()) {
            return AiCompletion::failed($this->name(), 'GEMINI_API_KEY missing');
        }

        $cfg = config('ai.providers.gemini');
        $model = (string) ($cfg['model'] ?? 'gemini-2.0-flash');
        // Never append API key to stored URL.
        $apiUrl = rtrim((string) ($cfg['base_url'] ?? ''), '/').'/models/'.$model.':generateContent';

        $payload = [
            'system_instruction' => [
                'parts' => [['text' => $system]],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $user]],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => $maxTokens,
                'temperature' => 0.7,
            ],
        ];

        try {
            $response = Http::timeout(25)
                ->withQueryParameters(['key' => $cfg['key']])
                ->post($apiUrl, $payload);

            $json = $response->json();
            $status = $response->status();

            if (! $response->successful()) {
                return AiCompletion::failed(
                    $this->name(),
                    is_array($json) ? (string) (data_get($json, 'error.message') ?? 'Gemini request failed') : 'Gemini request failed',
                    $apiUrl,
                    $status,
                    $payload,
                    $json ?? $response->body(),
                    $model,
                );
            }

            $parts = data_get($json, 'candidates.0.content.parts', []);
            $text = collect($parts)->pluck('text')->filter()->implode("\n");
            $tokens = (int) data_get($json, 'usageMetadata.totalTokenCount', 0);

            if (blank($text)) {
                return AiCompletion::failed(
                    $this->name(),
                    'Empty Gemini response',
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
