<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\SeoSite;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleBusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_listing_update_and_review_reply_use_the_business_profile_api(): void
    {
        config([
            'services.google.client_id' => 'gbp-client',
            'services.google.client_secret' => 'gbp-secret',
        ]);

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'vibgyorsolution.com',
            'status' => 'connected',
            'gbp_connected' => true,
            'gbp_token' => json_encode([
                'access_token' => 'ya-test',
                'expires_at' => now()->addHour()->timestamp,
            ]),
            'gbp_account_name' => 'accounts/9',
            'gbp_location_name' => 'locations/1',
            'gbp_location_title' => 'Vibgyor Solution',
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'mybusinessbusinessinformation.googleapis.com/v1/locations/1')) {
                if ($request->method() === 'PATCH') {
                    return Http::response(['name' => 'locations/1']);
                }

                return Http::response([
                    'name' => 'locations/1',
                    'title' => 'Vibgyor Solution',
                    'phoneNumbers' => ['primaryPhone' => '+919876543210', 'additionalPhones' => ['+919111112222']],
                    'websiteUri' => 'https://vibgyorsolution.com',
                    'profile' => ['description' => 'Web studio in Jaipur'],
                    'storefrontAddress' => ['addressLines' => ['MI Road'], 'locality' => 'Jaipur'],
                    'categories' => [
                        'primaryCategory' => ['displayName' => 'Website designer'],
                        'additionalCategories' => [['displayName' => 'Marketing agency']],
                    ],
                    'openInfo' => ['status' => 'OPEN'],
                    'metadata' => [
                        'placeId' => 'ChIJtest',
                        'mapsUri' => 'https://maps.google.com/?cid=1',
                        'hasVoiceOfMerchant' => true,
                    ],
                    'regularHours' => [
                        'periods' => [[
                            'openDay' => 'MONDAY',
                            'openTime' => ['hours' => 10, 'minutes' => 0],
                            'closeTime' => ['hours' => 19, 'minutes' => 0],
                        ]],
                    ],
                ]);
            }

            if (str_contains($url, 'mybusinessverifications.googleapis.com/v1/locations/1/VoiceOfMerchantState')) {
                return Http::response(['hasVoiceOfMerchant' => true, 'hasBusinessAuthority' => true]);
            }

            if (str_contains($url, 'mybusiness.googleapis.com/v4/accounts/9/locations/1/reviews')) {
                return Http::response([
                    'reviews' => [[
                        'name' => 'accounts/9/locations/1/reviews/abc',
                        'reviewer' => ['displayName' => 'Asha'],
                        'starRating' => 'FIVE',
                        'comment' => 'Great work',
                        'reviewReply' => ['comment' => 'Thanks Asha'],
                    ]],
                ]);
            }

            return Http::response(['error' => ['message' => 'unexpected '.$url]], 500);
        });

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->patch(route('seo.sites.gbp.update', $site), [
                'phone' => '+919876543210',
                'website' => 'https://vibgyorsolution.com',
                'description' => 'Web studio in Jaipur',
            ])
            ->assertRedirect(route('seo.index', ['site' => $site->id, 'tab' => 'business']));

        $site->refresh();
        $this->assertSame('+919876543210', $site->gbp_profile['phone']);
        $this->assertSame('Web studio in Jaipur', $site->gbp_profile['description']);
        $this->assertSame('MI Road, Jaipur', $site->gbp_profile['address']);
        $this->assertSame('Website designer', $site->gbp_profile['category']);
        $this->assertSame('Marketing agency', $site->gbp_profile['extra_categories']);
        $this->assertSame('Open', $site->gbp_profile['open_status']);
        $this->assertSame('verified', $site->gbp_profile['verification']['status']);
        $this->assertSame('Verified', $site->gbp_profile['verification']['label']);
        $this->assertSame('ChIJtest', $site->gbp_profile['place_id']);
        $this->assertSame(5, $site->gbp_reviews[0]['rating']);
        $this->assertNull($site->gbp_last_error);

        Http::assertSent(function ($request) {
            return $request->method() === 'PATCH'
                && str_contains($request->url(), 'updateMask=phoneNumbers.primaryPhone')
                && $request['phoneNumbers']['primaryPhone'] === '+919876543210'
                && $request['profile']['description'] === 'Web studio in Jaipur';
        });

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.sites.gbp.reviews.reply', $site), [
                'review_name' => 'accounts/9/locations/1/reviews/abc',
                'comment' => 'Thanks Asha',
            ])
            ->assertRedirect(route('seo.index', ['site' => $site->id, 'tab' => 'business']));

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_ends_with($request->url(), '/accounts/9/locations/1/reviews/abc/reply')
                && $request['comment'] === 'Thanks Asha';
        });
    }

    public function test_google_denial_is_stored_on_the_site(): void
    {
        config([
            'services.google.client_id' => 'gbp-client',
            'services.google.client_secret' => 'gbp-secret',
        ]);

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'vibgyorsolution.com',
            'status' => 'connected',
            'gbp_connected' => true,
            'gbp_token' => json_encode([
                'access_token' => 'ya-test',
                'expires_at' => now()->addHour()->timestamp,
            ]),
            'gbp_account_name' => 'accounts/9',
            'gbp_location_name' => 'locations/1',
        ]);

        Http::fake([
            'mybusinessbusinessinformation.googleapis.com/*' => Http::response([
                'error' => ['message' => 'The caller does not have permission'],
            ], 403),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.sites.gbp.refresh', $site))
            ->assertRedirect(route('seo.index', ['site' => $site->id, 'tab' => 'business']))
            ->assertSessionHas('error');

        $site->refresh();
        $this->assertStringContainsString('Business Profile API access', (string) $site->gbp_last_error);
    }
}
