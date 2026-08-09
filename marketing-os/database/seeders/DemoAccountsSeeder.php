<?php

namespace Database\Seeders;

use App\Enums\WorkspaceRole;
use App\Models\BrandKit;
use App\Models\SeoKeyword;
use App\Models\SeoSite;
use App\Models\SeoTask;
use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use Illuminate\Database\Seeder;

class DemoAccountsSeeder extends Seeder
{
    public function run(): void
    {
        $superadmin = User::query()->updateOrCreate(
            ['email' => 'superadmin@atlas.test'],
            [
                'name' => 'Super Admin',
                'password' => 'Password1!',
                'email_verified_at' => now(),
                'is_superadmin' => true,
            ]
        );

        // Migrate legacy demo client email if still present.
        User::query()
            ->where('email', 'client@atlas.test')
            ->update(['email' => 'info@vibgyorsolution.com']);

        $client = User::query()->updateOrCreate(
            ['email' => 'info@vibgyorsolution.com'],
            [
                'name' => 'Vibgyor Solution',
                'password' => 'Password1!',
                'email_verified_at' => now(),
                'is_superadmin' => false,
            ]
        );

        $workspace = Workspace::query()->firstOrCreate(
            ['slug' => 'vibgyor-solution'],
            ['name' => 'Vibgyor Solution']
        );

        if ($workspace->name !== 'Vibgyor Solution') {
            $workspace->update(['name' => 'Vibgyor Solution']);
        }

        // Fold legacy "Demo Client Co" into Vibgyor Solution when both exist.
        $legacy = Workspace::query()->where('slug', 'demo-client')->first();
        if ($legacy && $legacy->id !== $workspace->id) {
            foreach ($legacy->users as $member) {
                if (! $workspace->hasMember($member)) {
                    $workspace->users()->attach($member->id, [
                        'role' => $member->pivot->role,
                    ]);
                }
            }
            $legacy->delete();
        }

        if (! $workspace->hasMember($client)) {
            $workspace->users()->attach($client->id, [
                'role' => WorkspaceRole::Owner->value,
            ]);
        }

        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        $platform = Workspace::query()->firstOrCreate(
            ['slug' => 'atlas-platform'],
            ['name' => 'Atlas Platform']
        );

        if (! $platform->hasMember($superadmin)) {
            $platform->users()->attach($superadmin->id, [
                'role' => WorkspaceRole::Owner->value,
            ]);
        }

        BrandKit::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'name' => 'Default',
            ],
            [
                'is_active' => true,
                'primary_color' => '#0E9F90',
                'secondary_color' => '#0B1220',
                'font_family' => 'Plus Jakarta Sans',
                'website_url' => 'https://vibgyorsolution.com',
                'phone' => '+91 98765 43210',
                'email' => 'info@vibgyorsolution.com',
                'default_cta_label' => 'Book a call',
                'default_cta_url' => 'https://vibgyorsolution.com/contact',
                'social_links' => [
                    'instagram' => 'https://instagram.com/vibgyorsolution',
                    'linkedin' => 'https://linkedin.com/company/vibgyorsolution',
                ],
            ]
        );

        $ig = SocialAccount::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'platform' => 'instagram',
                'account_name' => 'Vibgyor Solution IG',
            ],
            ['status' => 'connected']
        );
        $ig->markConnected();

        // Drop leftover Demo Client IG if the Vibgyor account already exists.
        SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->where('account_name', 'Demo Client IG')
            ->delete();

        SocialPost::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'title' => 'Morning offer post',
            ],
            [
                'created_by' => $client->id,
                'body' => 'Fresh start this week. Book your free consult today.',
                'platforms' => ['instagram', 'facebook'],
                'status' => 'scheduled',
                'scheduled_at' => now()->addDay()->setTime(10, 0),
            ]
        );

        SocialPost::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'title' => 'Draft: weekend tips',
            ],
            [
                'created_by' => $client->id,
                'body' => '3 tips your customers need before the weekend.',
                'platforms' => ['linkedin'],
                'status' => 'draft',
            ]
        );

        // Prefer the live domain; drop the fake demo-client.test site if present.
        SeoSite::query()
            ->where('workspace_id', $workspace->id)
            ->where('domain', 'demo-client.test')
            ->delete();

        $site = SeoSite::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'domain' => 'vibgyorsolution.com',
            ],
            [
                'sitemap_url' => null,
                'status' => 'connected',
                'crawl_status' => 'idle',
            ]
        );

        // Do not seed fake pages/issues — audit data must come from a live crawl.

        SeoKeyword::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'keyword' => 'local digital marketing',
            ],
            [
                'group_name' => 'Core',
                'position' => 12,
                'position_change' => 2,
            ]
        );

        SeoKeyword::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'keyword' => 'seo agency near me',
            ],
            [
                'group_name' => 'Local',
                'position' => 8,
                'position_change' => -1,
            ]
        );

        SeoTask::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'title' => 'Connect a live website and run crawl',
            ],
            [
                'description' => 'Use a real public domain. RankwayAI audits only pages it can fetch.',
                'priority' => 'high',
                'status' => 'open',
                'due_on' => now()->toDateString(),
                'source' => 'manual',
            ]
        );

        SeoTask::query()->updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'title' => 'Add 5 target keywords with starting positions',
            ],
            [
                'description' => 'Keyword ranks are manual until GSC OAuth is configured.',
                'priority' => 'medium',
                'status' => 'open',
                'due_on' => now()->toDateString(),
                'source' => 'keyword',
            ]
        );
    }
}
