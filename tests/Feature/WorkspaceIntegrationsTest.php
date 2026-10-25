<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Mail\ChannelCampaignMail;
use App\Models\ChannelCampaign;
use App\Models\CrmLead;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceIntegration;
use App\Services\Access\ModuleAccess;
use App\Services\Billing\BillingService;
use App\Services\Channels\BrevoEmailDeliveryService;
use App\Services\Channels\ChannelCampaignService;
use App\Services\Channels\Rcs\RcsProviderCatalog;
use App\Services\Integrations\WorkspaceIntegrationService;
use App\Services\Social\SocialConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithWorkspace(string $plan = 'starter'): array
    {
        app(ModuleAccess::class)->setPlatformMenu('email', true);
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
        $this->assertSame('none', $channels->provider($workspace, 'whatsapp'));

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

        Mail::assertSent(ChannelCampaignMail::class, function ($mail) {
            return $mail->hasTo('asha@example.com');
        });
    }

    public function test_brevo_sends_email_campaigns_and_records_daily_free_usage(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        app(WorkspaceIntegrationService::class)->upsert($workspace, 'brevo', [
            'api_key' => 'brevo-test-key',
            'sender_email' => 'hello@brand.test',
            'sender_name' => 'Brand',
        ]);
        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<test-message-id>'], 201),
        ]);

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
                'name' => 'Brevo campaign',
                'channel' => 'email',
                'subject' => 'Hello {{name}}',
                'body' => 'Hi from {{brand}}',
                'delivery' => 'now',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $campaign = ChannelCampaign::query()->firstOrFail();
        $this->assertSame('auto', $campaign->provider);
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(299, app(BrevoEmailDeliveryService::class)->remainingFreeDailySends($workspace));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.brevo.com/v3/smtp/email'
            && $request->hasHeader('api-key', 'brevo-test-key')
            && $request['sender']['email'] === 'hello@brand.test'
            && $request['to'][0]['email'] === 'asha@example.com'
            && $request['subject'] === 'Hello Asha'
            && $request['htmlContent'] === '<html><body><p>Hi from '.$workspace->name.'</p></body></html>'
            && $request['textContent'] === 'Hi from '.$workspace->name);
    }

    public function test_email_campaign_automatically_falls_back_to_resend_after_brevo_daily_cap(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        app(WorkspaceIntegrationService::class)->upsert($workspace, 'brevo', [
            'api_key' => 'brevo-rotation-key',
            'sender_email' => 'hello@brand.test',
            'sender_name' => 'Brand',
        ]);
        app(WorkspaceIntegrationService::class)->upsert($workspace, 'resend', [
            'api_key' => 're_test_rotation_key',
            'sender_email' => 'hello@brand.test',
            'sender_name' => 'Brand',
        ]);
        DB::table('email_provider_daily_usages')->insert([
            'id' => (string) Str::uuid7(),
            'provider' => 'brevo',
            'credential_hash' => hash('sha256', 'brevo-rotation-key'),
            'usage_date' => now('UTC')->toDateString(),
            'reserved_count' => BrevoEmailDeliveryService::FREE_DAILY_LIMIT,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Http::fake([
            'api.resend.com/emails' => Http::response(['id' => 'resend-message-id'], 200),
        ]);
        CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Asha',
            'email' => 'asha@example.com',
            'stage' => 'new',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('email.store'), [
                'name' => 'Automatic rotation',
                'subject' => 'Hello',
                'body' => '<p>Hello {{name}}</p>',
                'delivery' => 'now',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $campaign = ChannelCampaign::query()->firstOrFail();
        $this->assertSame('auto', $campaign->provider);
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(2999, app(BrevoEmailDeliveryService::class)->remainingMonthlySends($workspace, 'resend'));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.resend.com/emails'
            && $request->hasHeader('Authorization', 'Bearer re_test_rotation_key')
            && $request['from'] === 'Brand <hello@brand.test>'
            && $request['to'] === ['asha@example.com']
            && $request['subject'] === 'Hello'
            && $request['html'] === '<p>Hello Asha</p>');
        Http::assertNotSent(fn ($request) => $request->url() === 'https://api.brevo.com/v3/smtp/email');
    }

    public function test_resend_mailersend_and_sender_send_and_track_monthly_free_usage(): void
    {
        [, $workspace] = $this->memberWithWorkspace();
        $integrations = app(WorkspaceIntegrationService::class);
        foreach (['resend', 'mailersend', 'sender'] as $provider) {
            $integrations->upsert($workspace, $provider, [
                'api_key' => $provider.'-test-key',
                'sender_email' => 'hello@brand.test',
                'sender_name' => 'Brand',
            ]);
        }
        Http::fake([
            'api.resend.com/emails' => Http::response(['id' => 'resend-id'], 200),
            'api.mailersend.com/v1/email' => Http::response([], 202, ['x-message-id' => 'mailersend-id']),
            'api.sender.net/v2/message/send' => Http::response(['emailId' => 'sender-id'], 200),
        ]);
        $delivery = app(BrevoEmailDeliveryService::class);

        $this->assertTrue($delivery->sendProvider($workspace, 'resend', 'one@example.com', 'Hello', '<p>Hi</p>')['ok']);
        $this->assertTrue($delivery->sendProvider($workspace, 'mailersend', 'two@example.com', 'Hello', '<p>Hi</p>')['ok']);
        $this->assertTrue($delivery->sendProvider($workspace, 'sender', 'three@example.com', 'Hello', '<p>Hi</p>')['ok']);

        $this->assertSame(2999, $delivery->remainingMonthlySends($workspace, 'resend'));
        $this->assertSame(499, $delivery->remainingMonthlySends($workspace, 'mailersend'));
        $this->assertSame(14999, $delivery->remainingMonthlySends($workspace, 'sender'));
        Http::assertSent(fn ($request) => $request->url() === 'https://api.mailersend.com/v1/email'
            && $request->hasHeader('Authorization', 'Bearer mailersend-test-key')
            && $request['to'][0]['email'] === 'two@example.com');
        Http::assertSent(fn ($request) => $request->url() === 'https://api.sender.net/v2/message/send'
            && $request->hasHeader('Authorization', 'Bearer sender-test-key')
            && $request['to']['email'] === 'three@example.com');
    }

    public function test_email_campaign_does_not_fake_a_success_when_no_provider_is_connected(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('email.store'), [
                'name' => 'No provider',
                'subject' => 'Hello',
                'body' => '<p>Hello</p>',
                'delivery' => 'now',
            ])
            ->assertSessionHasErrors('email_provider');

        $this->assertDatabaseMissing('channel_campaigns', [
            'workspace_id' => $workspace->id,
            'name' => 'No provider',
        ]);
    }

    public function test_email_campaign_sends_sanitized_rich_html_and_escapes_lead_placeholders(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        app(WorkspaceIntegrationService::class)->upsert($workspace, 'brevo', [
            'api_key' => 'brevo-rich-test-key',
            'sender_email' => 'hello@brand.test',
            'sender_name' => 'Brand',
        ]);
        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response(['messageId' => '<rich-test>'], 201),
        ]);
        CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => '<img src=x onerror=alert(1)>',
            'email' => 'asha@example.com',
            'stage' => 'new',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('email.store'), [
                'name' => 'Rich email',
                'subject' => 'Hello',
                'body' => '<h2>Welcome {{name}}</h2><script>alert(1)</script><p><a href="javascript:alert(1)" onclick="alert(1)">Read more</a></p>',
                'delivery' => 'now',
            ])
            ->assertRedirect();

        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.brevo.com/v3/smtp/email') {
                return false;
            }

            $html = $request['htmlContent'];

            return str_contains($html, '<h2>Welcome &lt;img src=x onerror=alert(1)&gt;</h2>')
                && str_contains($html, '<p><a>Read more</a></p>')
                && ! str_contains($html, '<script')
                && ! str_contains($html, 'javascript:')
                && ! str_contains($html, 'onclick=');
        });
    }

    public function test_brevo_daily_free_limit_stops_campaign_without_sending(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        app(WorkspaceIntegrationService::class)->upsert($workspace, 'brevo', [
            'api_key' => 'brevo-test-key',
            'sender_email' => 'hello@brand.test',
            'sender_name' => 'Brand',
        ]);
        DB::table('email_provider_daily_usages')->insert([
            'id' => (string) Str::uuid7(),
            'provider' => 'brevo',
            'credential_hash' => hash('sha256', 'brevo-test-key'),
            'usage_date' => now('UTC')->toDateString(),
            'reserved_count' => 300,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Http::fake();

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
                'name' => 'Brevo campaign',
                'channel' => 'email',
                'subject' => 'Hello',
                'body' => 'Hi',
                'delivery' => 'now',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $campaign = ChannelCampaign::query()->firstOrFail();
        $this->assertSame('failed', $campaign->status);
        $this->assertStringContainsString('free-plan sending limit reached', $campaign->failure_reason);
        $this->assertSame('failed', $campaign->recipients()->value('status'));
        Http::assertNothingSent();
    }

    public function test_brevo_rate_limit_stops_remaining_campaign_sends(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        app(WorkspaceIntegrationService::class)->upsert($workspace, 'brevo', [
            'api_key' => 'brevo-test-key',
            'sender_email' => 'hello@brand.test',
            'sender_name' => 'Brand',
        ]);
        Http::fake([
            'api.brevo.com/v3/smtp/email' => Http::response([
                'code' => 'too_many_requests',
                'message' => 'Rate limit exceeded',
            ], 429),
        ]);
        foreach (['Asha', 'Ravi'] as $name) {
            CrmLead::query()->create([
                'workspace_id' => $workspace->id,
                'name' => $name,
                'email' => strtolower($name).'@example.com',
                'stage' => 'new',
                'source' => 'manual',
            ]);
        }

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('channels.store'), [
                'name' => 'Brevo campaign',
                'channel' => 'email',
                'subject' => 'Hello',
                'body' => 'Hi',
                'delivery' => 'now',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $campaign = ChannelCampaign::query()->firstOrFail();
        $this->assertSame('failed', $campaign->status);
        $this->assertSame(2, $campaign->failed_count);
        $this->assertStringContainsString('sending limit reached', $campaign->failure_reason);
        Http::assertSentCount(1);
    }
}
