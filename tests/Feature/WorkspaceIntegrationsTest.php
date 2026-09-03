<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\ChannelCampaign;
use App\Models\CrmLead;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIntegration;
use App\Services\Billing\BillingService;
use App\Services\Channels\ChannelCampaignService;
use App\Services\Channels\Rcs\RcsProviderCatalog;
use App\Services\Integrations\WorkspaceIntegrationService;
use App\Services\Social\SocialConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WorkspaceIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithWorkspace(string $plan = 'starter'): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, $plan, 'active');

        return [$user, $workspace];
    }

    public function test_integrations_dashboard_renders(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('settings.index', ['tab' => 'providers']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Settings/Index')
                ->has('integrations')
                ->has('categories'));
    }

    public function test_legacy_integrations_url_redirects_to_settings(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('integrations.index'))
            ->assertRedirect('/settings?tab=providers');
    }

    public function test_client_can_save_and_disconnect_zavu_credentials(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->put(route('integrations.update', 'zavu'), [
                'enabled' => true,
                'credentials' => [
                    'api_key' => 'zavu_test_key_1234',
                    'base_url' => 'https://api.zavu.dev',
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $row = WorkspaceIntegration::query()->where('workspace_id', $workspace->id)->where('provider', 'zavu')->first();
        $this->assertNotNull($row);
        $this->assertTrue($row->enabled);
        $this->assertSame('connected', $row->status);
        $this->assertSame('zavu_test_key_1234', $row->credential('api_key'));

        $service = app(WorkspaceIntegrationService::class);
        $this->assertSame('zavu_test_key_1234', $service->zavuKey($workspace));
        $this->assertSame('zavu', app(ChannelCampaignService::class)->provider($workspace, 'whatsapp'));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('integrations.disconnect', 'zavu'))
            ->assertRedirect();

        $this->assertFalse($service->get($workspace, 'zavu')?->enabled ?? false);
        $this->assertNull($service->get($workspace, 'zavu'));
    }

    public function test_provider_keys_stay_on_that_workspace_only(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $other = Workspace::factory()->create(['name' => 'Second Brand']);
        $other->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($other, 'starter', 'active');

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->put(route('integrations.update', 'zavu'), [
                'enabled' => true,
                'credentials' => [
                    'api_key' => 'workspace_one_key',
                    'base_url' => 'https://api.zavu.dev',
                ],
            ])
            ->assertRedirect();

        $service = app(WorkspaceIntegrationService::class);
        $this->assertSame('workspace_one_key', $service->zavuKey($workspace));
        $this->assertNull($service->zavuKey($other));
    }

    public function test_workspace_jio_credentials_mark_rcs_ready(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        app(WorkspaceIntegrationService::class)->upsert($workspace, 'jio', [
            'base_url' => 'https://rcs.jio.example/api',
            'client_id' => 'jio-client',
            'client_secret' => 'jio-secret',
            'agent_id' => 'agent-1',
        ]);

        $providers = RcsProviderCatalog::available($workspace);
        $jio = collect($providers)->firstWhere('id', 'jio');

        $this->assertNotNull($jio);
        $this->assertTrue($jio['ready']);

        $cfg = RcsProviderCatalog::config('jio', $workspace);
        $this->assertSame('https://rcs.jio.example/api', $cfg['base_url']);
        $this->assertSame('jio-client', $cfg['client_id']);
    }

    public function test_meta_workspace_keys_enable_oauth_mode(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        app(WorkspaceIntegrationService::class)->upsert($workspace, 'meta', [
            'app_id' => 'meta-app-id',
            'app_secret' => 'meta-app-secret',
        ]);

        $modes = app(SocialConnectionService::class)->modes($workspace);
        $this->assertSame('oauth', $modes['facebook']);
        $this->assertSame('oauth', $modes['instagram']);
        $this->assertSame('sandbox', $modes['linkedin']);

        $url = app(SocialConnectionService::class)->oauthAuthorizeUrl($workspace, 'facebook');
        $this->assertNotNull($url);
        $this->assertStringContainsString('meta-app-id', $url);
    }

    public function test_server_meta_env_keys_do_not_enable_social_oauth_without_workspace_keys(): void
    {
        [, $workspace] = $this->memberWithWorkspace();

        config([
            'services.meta.app_id' => 'global-meta-app',
            'services.meta.app_secret' => 'global-meta-secret',
        ]);

        $integrations = app(WorkspaceIntegrationService::class);
        $this->assertFalse($integrations->workspaceSocialOAuthReady($workspace, 'meta'));

        $modes = app(SocialConnectionService::class)->modes($workspace);
        $this->assertSame('sandbox', $modes['facebook']);
        $this->assertSame('sandbox', $modes['instagram']);
        $this->assertNull(
            app(SocialConnectionService::class)->oauthAuthorizeUrl($workspace, 'facebook')
        );
    }

    public function test_social_index_exposes_workspace_meta_provider_status(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('social.index', ['view' => 'accounts']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Social/Index')
                ->where('social_providers.meta.configured', false)
                ->has('social_providers.meta.settings_url'));
    }

    public function test_remove_keys_deletes_integration_row(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        app(WorkspaceIntegrationService::class)->upsert($workspace, 'linkedin', [
            'client_id' => 'li-id',
            'client_secret' => 'li-secret',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->delete(route('integrations.destroy', 'linkedin'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('workspace_integrations', [
            'workspace_id' => $workspace->id,
            'provider' => 'linkedin',
        ]);
    }

    public function test_custom_smtp_is_preferred_for_email_campaigns(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->put(route('integrations.update', 'smtp'), [
                'enabled' => true,
                'credentials' => [
                    'host' => 'smtp.mail.test',
                    'port' => '587',
                    'encryption' => 'tls',
                    'username' => 'user@mail.test',
                    'password' => 'secret-pass',
                    'from_address' => 'hello@brand.test',
                    'from_name' => 'Brand',
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $service = app(WorkspaceIntegrationService::class);
        $this->assertTrue($service->hasSmtp($workspace));
        $this->assertSame('smtp.mail.test', $service->smtpConfig($workspace)['host']);

        $channels = app(ChannelCampaignService::class);
        $this->assertSame('smtp', $channels->provider($workspace, 'email'));
        $this->assertSame('sandbox', $channels->provider($workspace, 'whatsapp'));

        Mail::fake();

        CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Asha',
            'email' => 'asha@example.com',
            'stage' => 'new',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('channels.store'), [
                'name' => 'SMTP blast',
                'channel' => 'email',
                'subject' => 'Hello {{name}}',
                'body' => 'Hi from {{brand}}',
                'delivery' => 'now',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $campaign = ChannelCampaign::query()->first();
        $this->assertSame('smtp', $campaign->provider);
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(1, $campaign->sent_count);

        Mail::assertSent(\App\Mail\ChannelCampaignMail::class, function ($mail) {
            return $mail->hasTo('asha@example.com');
        });
    }
}
