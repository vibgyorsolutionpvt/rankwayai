<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\CrmLead;
use App\Models\Funnel;
use App\Models\FunnelLead;
use App\Models\SeoSite;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseoutIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

        return [$user, $workspace];
    }

    public function test_personal_profile_sandbox_connect(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.accounts.store'), [
                'platform' => 'instagram',
                'account_name' => 'Anil Personal',
                'account_type' => 'profile',
            ])
            ->assertRedirect();

        $account = SocialAccount::query()->first();
        $this->assertSame('profile', $account->account_type);
        $this->assertSame('sandbox', $account->connection_mode);
        $this->assertSame('connected', $account->status);
    }

    public function test_pagespeed_requires_api_key(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $site = SeoSite::query()->create([
            'workspace_id' => $workspace->id,
            'domain' => 'example.com',
            'status' => 'connected',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('seo.sites.pagespeed', $site))
            ->assertRedirect()
            ->assertSessionHas('error');
    }

    public function test_funnel_publish_and_lead_capture(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('funnels.store'), [
                'name' => 'Launch offer',
                'headline' => 'Grow faster',
                'subheadline' => 'Book a demo',
                'status' => 'draft',
            ])
            ->assertRedirect();

        $funnel = Funnel::query()->first();
        $this->assertNotNull($funnel);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->patch(route('funnels.update', $funnel), ['status' => 'published'])
            ->assertRedirect();

        $this->get(route('funnels.public', $funnel->slug))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Funnels/Public'));

        $this->post(route('funnels.lead', $funnel->slug), [
            'name' => 'Priya',
            'email' => 'priya@example.com',
            'phone' => '+919999999999',
        ])->assertRedirect();

        $this->assertSame(1, FunnelLead::query()->count());
        $this->assertSame(1, CrmLead::query()->where('source', 'funnel:'.$funnel->slug)->count());
        $this->assertSame(1, $funnel->fresh()->leads);
        $this->assertGreaterThan(0, $funnel->fresh()->views);
    }
}
