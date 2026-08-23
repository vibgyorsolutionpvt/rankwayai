<?php

namespace Tests\Feature;

use App\Services\Ai\AiProviderRouter;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiCompatibleProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderRouterTest extends TestCase
{
    private function freeStackConfig(): void
    {
        config([
            'ai.default' => 'auto',
            'ai.priority' => ['groq', 'cerebras', 'gemini', 'mistral', 'openrouter', 'openai'],
            'ai.providers.groq.key' => 'groq-test',
            'ai.providers.groq.model' => 'llama-3.3-70b-versatile',
            'ai.providers.groq.base_url' => 'https://api.groq.com/openai/v1',
            'ai.providers.cerebras.key' => 'cerebras-test',
            'ai.providers.cerebras.model' => 'llama-3.3-70b',
            'ai.providers.cerebras.base_url' => 'https://api.cerebras.ai/v1',
            'ai.providers.gemini.key' => 'gemini-test',
            'ai.providers.gemini.model' => 'gemini-2.0-flash',
            'ai.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'ai.providers.mistral.key' => null,
            'ai.providers.openrouter.key' => null,
            'ai.providers.openai.key' => null,
        ]);
    }

    public function test_auto_prefers_groq_among_free_stack(): void
    {
        $this->freeStackConfig();

        $router = new AiProviderRouter;
        $this->assertSame('groq', $router->activeName());
    }

    public function test_groq_completion_parses_response(): void
    {
        config([
            'ai.providers.groq.key' => 'groq-test',
            'ai.providers.groq.model' => 'llama-3.3-70b-versatile',
            'ai.providers.groq.base_url' => 'https://api.groq.com/openai/v1',
        ]);

        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '{"ok":true}']],
                ],
                'usage' => ['total_tokens' => 42],
            ], 200),
        ]);

        $result = (new OpenAiCompatibleProvider('groq'))->complete('sys', 'user');
        $this->assertTrue($result->ok);
        $this->assertSame('groq', $result->provider);
        $this->assertSame('{"ok":true}', $result->text);
        $this->assertSame(42, $result->tokens);
    }

    public function test_cerebras_and_mistral_are_openai_compatible(): void
    {
        config([
            'ai.providers.cerebras.key' => 'cb-key',
            'ai.providers.cerebras.model' => 'llama-3.3-70b',
            'ai.providers.cerebras.base_url' => 'https://api.cerebras.ai/v1',
            'ai.providers.mistral.key' => 'mistral-key',
            'ai.providers.mistral.model' => 'mistral-small-latest',
            'ai.providers.mistral.base_url' => 'https://api.mistral.ai/v1',
        ]);

        Http::fake([
            'api.cerebras.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'cerebras ok']]],
            ], 200),
            'api.mistral.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'mistral ok']]],
            ], 200),
        ]);

        $this->assertSame('cerebras ok', (new OpenAiCompatibleProvider('cerebras'))->complete('s', 'u')->text);
        $this->assertSame('mistral ok', (new OpenAiCompatibleProvider('mistral'))->complete('s', 'u')->text);
    }

    public function test_gemini_completion_parses_response(): void
    {
        config([
            'ai.providers.gemini.key' => 'gemini-test',
            'ai.providers.gemini.model' => 'gemini-2.0-flash',
            'ai.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    [
                        'content' => [
                            'parts' => [['text' => 'hello from gemini']],
                        ],
                    ],
                ],
                'usageMetadata' => ['totalTokenCount' => 11],
            ], 200),
        ]);

        $result = (new GeminiProvider)->complete('sys', 'user');
        $this->assertTrue($result->ok);
        $this->assertSame('gemini', $result->provider);
        $this->assertSame('hello from gemini', $result->text);
    }

    public function test_failover_to_gemini_when_groq_fails(): void
    {
        $this->freeStackConfig();
        config([
            'ai.providers.cerebras.key' => null,
        ]);

        Http::fake([
            'api.groq.com/*' => Http::response(['error' => ['message' => 'rate limit']], 429),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'fallback ok']]]],
                ],
            ], 200),
        ]);

        $result = (new AiProviderRouter)->complete('sys', 'user');
        $this->assertTrue($result->ok);
        $this->assertSame('gemini', $result->provider);
        $this->assertSame('fallback ok', $result->text);
    }

    public function test_status_lists_free_and_paid_providers(): void
    {
        $status = (new AiProviderRouter)->status();
        $ids = collect($status)->pluck('id')->all();

        $this->assertContains('groq', $ids);
        $this->assertContains('cerebras', $ids);
        $this->assertContains('mistral', $ids);
        $this->assertContains('openrouter', $ids);
        $this->assertContains('gemini', $ids);
        $this->assertContains('openai', $ids);
        $this->assertNotContains('ollama', $ids);
    }
}
