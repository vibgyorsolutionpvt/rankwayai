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
        $model = $cfg['model'] ?? 'gemini-2.0-flash';
        $url = rtrim($cfg['base_url'] ?? '', '/').'/models/'.$model.':generateContent';

        try {
            $response = Http::timeout(25)
                ->withQueryParameters(['key' => $cfg['key']])
                ->post($url, [
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
                ]);

            if (! $response->successful()) {
                return AiCompletion::failed(
                    $this->name(),
                    $response->json('error.message') ?? 'Gemini request failed'
                );
            }

            $parts = data_get($response->json(), 'candidates.0.content.parts', []);
            $text = collect($parts)->pluck('text')->filter()->implode("\n");
            $tokens = (int) data_get($response->json(), 'usageMetadata.totalTokenCount', 0);

            if (blank($text)) {
                return AiCompletion::failed($this->name(), 'Empty Gemini response');
            }

            return new AiCompletion($text, $this->name(), $tokens);
        } catch (\Throwable $e) {
            return AiCompletion::failed($this->name(), $e->getMessage());
        }
    }
}
