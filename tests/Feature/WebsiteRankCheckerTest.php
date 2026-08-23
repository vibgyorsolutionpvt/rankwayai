<?php

namespace Tests\Feature;

use App\Models\RankwayDomain;
use App\Models\User;
use App\Services\Rankway\RankwayDomainAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebsiteRankCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_checker_page_loads(): void
    {
        $this->get(route('website-rank-checker'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Marketing/WebsiteRankChecker'));
    }

    public function test_check_analyzes_domain_and_redirects(): void
    {
        Http::fake([
            'https://example.com' => Http::response(
                '<html><head><title>Example Domain</title><meta name="description" content="Example site used for documentation and examples about the web."><meta name="viewport" content="width=device-width"><link rel="canonical" href="https://example.com/"></head><body><h1>Example</h1><p>'.str_repeat('word ', 120).'</p><img src="/a.png" alt="a"></body></html>',
                200
            ),
        ]);

        $this->post(route('website-rank-checker.check'), [
            'domain' => 'https://www.example.com/path',
        ])->assertRedirect();

        $domain = RankwayDomain::query()->where('domain', 'example.com')->first();
        $this->assertNotNull($domain);
        $this->assertSame('ready', $domain->status);
        $this->assertNotNull($domain->rankway_score);
        $this->assertNotNull($domain->global_rank);
    }

    public function test_guest_result_locks_sensitive_metrics(): void
    {
        $domain = RankwayDomain::query()->create([
            'domain' => 'locked.test',
            'url' => 'https://locked.test',
            'rankway_score' => 70,
            'global_rank' => 1,
            'country_rank' => 1,
            'status' => 'ready',
            'last_analyzed_at' => now(),
        ]);

        $payload = $domain->toPublicArray(unlocked: false);
        $this->assertContains('backlinks', $payload['locked']);
        $this->assertNull($payload['metrics']['backlinks']);
    }

    public function test_auth_user_unlocks_metrics_on_result_page(): void
    {
        Http::fake([
            'https://example.com' => Http::response(
                '<html><head><title>Example Domain Site Title Here</title><meta name="description" content="Example site used for documentation and examples about the web platform."><meta name="viewport" content="width=device-width"><link rel="canonical" href="https://example.com/"></head><body><h1>Example</h1><p>'.str_repeat('content ', 150).'</p></body></html>',
                200
            ),
        ]);

        app(RankwayDomainAnalyzer::class)->analyze('example.com');
        $domain = RankwayDomain::query()->where('domain', 'example.com')->firstOrFail();

        $user = User::factory()->create();
        $this->actingAs($user)
            ->get(route('website-rank-checker', ['id' => $domain->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Marketing/WebsiteRankChecker')
                ->where('unlocked', true)
                ->where('result.unlocked', true)
            );
    }

    public function test_facebook_scores_above_probe_only_new_site(): void
    {
        RankwayDomain::query()->delete();

        Http::fake([
            'https://vibgyorholidays.com' => Http::response(
                '<html><head><title>Best Holiday Packages & Tour Deals | Vibgyor Holidays</title>'
                .'<meta name="description" content="'.str_repeat('Holiday packages and tour deals across India. ', 8).'">'
                .'<meta name="viewport" content="width=device-width"><link rel="canonical" href="https://vibgyorholidays.com/"></head>'
                .'<body><h1>Holidays</h1><p>'.str_repeat('travel ', 200).'</p><img src="/a.png" alt="a"></body></html>',
                200
            ),
            'https://facebook.com' => Http::response(
                '<html><head><title>Facebook</title></head><body><p>login</p></body></html>',
                200
            ),
        ]);

        $analyzer = app(RankwayDomainAnalyzer::class);
        $newSite = $analyzer->analyze('vibgyorholidays.com', force: true);
        $facebook = $analyzer->analyze('facebook.com', force: true);

        $this->assertLessThanOrEqual(72, $newSite->rankway_score);
        $this->assertGreaterThan($newSite->rankway_score, $facebook->rankway_score);
        $this->assertSame(1, $facebook->fresh()->global_rank);
        $this->assertSame(2, $newSite->fresh()->global_rank);
    }

    public function test_rank_preview_hides_absolute_rank_when_index_small(): void
    {
        $domain = RankwayDomain::query()->create([
            'domain' => 'preview.test',
            'rankway_score' => 60,
            'global_rank' => 1,
            'status' => 'ready',
            'last_analyzed_at' => now(),
        ]);

        $payload = $domain->toPublicArray();
        $this->assertTrue($payload['rank_preview']);
        $this->assertNull($payload['global_rank']);
        $this->assertSame('#1 of 1 analyzed', $payload['rank_among_indexed']);
    }
}
