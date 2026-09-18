<?php

namespace Tests\Feature;

use App\Services\Ai\AiProviderRouter;
use App\Services\Ai\Providers\GeminiProvider;
use App\Services\Ai\Providers\OpenAiCompatibleProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiProviderRouterTest extends TestCase
{
    private function paidPrimaryConfig(): void
    {
        config([
            'ai.default' => 'openai',
            'ai.priority' => ['openai', 'openrouter', 'groq', 'gemini', 'mistral', 'cerebras'],
            'ai.failover.max_attempts' => 2,
            'ai.failover.cooldown_seconds' => 900,
            'ai.failover.sticky_ttl_seconds' => 3600,
            'ai.providers.openai.key' => 'openai-test',
            'ai.providers.openai.model' => 'gpt-4o-mini',
            'ai.providers.openai.base_url' => 'https://api.openai.com/v1',
            'ai.providers.openai.tier' => 'paid',
            'ai.providers.openrouter.key' => null,
            'ai.providers.groq.key' => 'groq-test',
            'ai.providers.groq.model' => 'openai/gpt-oss-20b',
            'ai.providers.groq.base_url' => 'https://api.groq.com/openai/v1',
            'ai.providers.groq.tier' => 'free',
            'ai.providers.gemini.key' => null,
            'ai.providers.mistral.key' => null,
            'ai.providers.cerebras.key' => null,
        ]);
        Cache::flush();
    }

    public function test_default_prefers_openai(): void
    {
        $this->paidPrimaryConfig();

        $router = new AiProviderRouter;
        $this->assertSame('openai', $router->activeName());
    }

    public function test_groq_completion_parses_response(): void
    {
        config([
            'ai.providers.groq.key' => 'groq-test',
            'ai.providers.groq.model' => 'openai/gpt-oss-20b',
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
            'ai.providers.cerebras.model' => 'llama3.1-8b',
            'ai.providers.cerebras.base_url' => 'https://api.cerebras.ai/v1',
            'ai.providers.mistral.key' => 'mistral-key',
            'ai.providers.mistral.model' => 'open-mistral-nemo',
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
            'ai.providers.gemini.model' => 'gemini-flash-latest',
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

    public function test_failover_from_openai_to_groq_backup(): void
    {
        $this->paidPrimaryConfig();
        config(['ai.failover.max_attempts' => 2]);

        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'openai down']], 500),
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'backup ok']]],
            ], 200),
        ]);

        $result = (new AiProviderRouter)->complete('sys', 'user');
        $this->assertTrue($result->ok);
        $this->assertSame('groq', $result->provider);
        $this->assertSame('backup ok', $result->text);
        $this->assertCount(2, $result->attempts);
    }

    public function test_max_attempts_stops_after_two(): void
    {
        $this->paidPrimaryConfig();
        config([
            'ai.failover.max_attempts' => 2,
            'ai.providers.gemini.key' => 'gemini-test',
            'ai.providers.gemini.model' => 'gemini-flash-latest',
            'ai.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'ai.providers.gemini.tier' => 'free',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'down']], 500),
            'api.groq.com/*' => Http::response(['error' => ['message' => 'down']], 429),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'should not reach']]]]],
            ], 200),
        ]);

        $result = (new AiProviderRouter)->complete('sys', 'user');
        $this->assertFalse($result->ok);
        $this->assertCount(2, $result->attempts);
    }

    public function test_sticky_provider_is_tried_first_after_success(): void
    {
        $this->paidPrimaryConfig();
        config(['ai.failover.max_attempts' => 2]);

        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'down']], 503),
            'api.groq.com/*' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => 'groq ok']]]], 200)
                ->push(['choices' => [['message' => ['content' => 'sticky ok']]]], 200),
        ]);

        $router = new AiProviderRouter;
        $first = $router->complete('sys', 'user');
        $this->assertSame('groq', $first->provider);

        $second = $router->complete('sys', 'user');
        $this->assertTrue($second->ok);
        $this->assertSame('groq', $second->provider);
        $this->assertSame('sticky ok', $second->text);
        $this->assertSame('groq', $second->attempts[0]['provider'] ?? null);
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
