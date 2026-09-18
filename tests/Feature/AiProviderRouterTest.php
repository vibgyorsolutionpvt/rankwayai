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
    private function openaiOnlyConfig(): void
    {
        config([
            'ai.default' => 'openai',
            'ai.priority' => ['openai'],
            'ai.failover.max_attempts' => 1,
            'ai.failover.cooldown_seconds' => 900,
            'ai.failover.sticky_ttl_seconds' => 3600,
            'ai.providers' => [
                'openai' => [
                    'label' => 'OpenAI',
                    'tier' => 'paid',
                    'key' => 'openai-test',
                    'model' => 'gpt-4o-mini',
                    'base_url' => 'https://api.openai.com/v1',
                ],
            ],
        ]);
        Cache::flush();
    }

    public function test_default_prefers_openai(): void
    {
        $this->openaiOnlyConfig();

        $router = new AiProviderRouter;
        $this->assertSame('openai', $router->activeName());
    }

    public function test_openai_completion_parses_response(): void
    {
        $this->openaiOnlyConfig();

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => '{"ok":true}']],
                ],
                'usage' => ['total_tokens' => 42],
            ], 200),
        ]);

        $result = (new OpenAiCompatibleProvider('openai'))->complete('sys', 'user');
        $this->assertTrue($result->ok);
        $this->assertSame('openai', $result->provider);
        $this->assertSame('{"ok":true}', $result->text);
        $this->assertSame(42, $result->tokens);
    }

    public function test_disabled_provider_classes_still_parse_when_configured_in_test(): void
    {
        // Provider classes remain in codebase; only config registration is disabled.
        config([
            'ai.providers.groq.key' => 'groq-test',
            'ai.providers.groq.model' => 'openai/gpt-oss-20b',
            'ai.providers.groq.base_url' => 'https://api.groq.com/openai/v1',
            'ai.providers.cerebras.key' => 'cb-key',
            'ai.providers.cerebras.model' => 'llama3.1-8b',
            'ai.providers.cerebras.base_url' => 'https://api.cerebras.ai/v1',
            'ai.providers.mistral.key' => 'mistral-key',
            'ai.providers.mistral.model' => 'open-mistral-nemo',
            'ai.providers.mistral.base_url' => 'https://api.mistral.ai/v1',
            'ai.providers.gemini.key' => 'gemini-test',
            'ai.providers.gemini.model' => 'gemini-flash-latest',
            'ai.providers.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
        ]);

        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'groq ok']]],
            ], 200),
            'api.cerebras.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'cerebras ok']]],
            ], 200),
            'api.mistral.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'mistral ok']]],
            ], 200),
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'hello from gemini']]]],
                ],
                'usageMetadata' => ['totalTokenCount' => 11],
            ], 200),
        ]);

        $this->assertSame('groq ok', (new OpenAiCompatibleProvider('groq'))->complete('s', 'u')->text);
        $this->assertSame('cerebras ok', (new OpenAiCompatibleProvider('cerebras'))->complete('s', 'u')->text);
        $this->assertSame('mistral ok', (new OpenAiCompatibleProvider('mistral'))->complete('s', 'u')->text);

        $gemini = (new GeminiProvider)->complete('sys', 'user');
        $this->assertTrue($gemini->ok);
        $this->assertSame('hello from gemini', $gemini->text);
    }

    public function test_router_does_not_failover_to_free_apis(): void
    {
        $this->openaiOnlyConfig();
        // Even if free keys exist in env-style config, router only knows openai.
        config([
            'ai.providers.groq' => [
                'label' => 'Groq',
                'tier' => 'free',
                'key' => 'groq-test',
                'model' => 'openai/gpt-oss-20b',
                'base_url' => 'https://api.groq.com/openai/v1',
            ],
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'down']], 500),
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'should not reach']]],
            ], 200),
        ]);

        // Fresh router after config change
        $result = (new AiProviderRouter)->complete('sys', 'user');
        $this->assertFalse($result->ok);
        $this->assertSame('openai', $result->provider);
        $this->assertCount(1, $result->attempts);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.groq.com'));
    }

    public function test_blocks_when_openai_key_missing(): void
    {
        $this->openaiOnlyConfig();
        config(['ai.providers.openai.key' => null]);

        $result = (new AiProviderRouter)->complete('sys', 'user');
        $this->assertFalse($result->ok);
        $this->assertStringContainsString('OPENAI_API_KEY missing', (string) $result->error);
        Http::assertNothingSent();
    }

    public function test_status_lists_openai_only(): void
    {
        $this->openaiOnlyConfig();
        $status = (new AiProviderRouter)->status();
        $ids = collect($status)->pluck('id')->all();

        $this->assertSame(['openai'], $ids);
    }
}
