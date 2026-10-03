<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\ChannelMessageTemplate;
use App\Models\CrmLead;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use App\Services\Channels\ChannelCampaignService;
use App\Services\Integrations\WorkspaceIntegrationService;
use App\Services\WhatsApp\MetaWhatsAppCloudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppHubTest extends TestCase
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

    public function test_whatsapp_hub_renders_tabs(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('whatsapp.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('WhatsApp/Index')
                ->where('view', 'conversations')
                ->has('conversations')
                ->has('templates')
                ->has('campaigns'));
    }

    private function connectMeta(Workspace $workspace): void
    {
        app(WorkspaceIntegrationService::class)->upsert($workspace, 'whatsapp_meta', [
            'phone_number_id' => '1234567890',
            'access_token' => 'meta_token_secret',
            'verify_token' => 'verify',
            'api_version' => 'v21.0',
        ]);
    }

    public function test_business_details_without_meta_credentials_stay_not_connected(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('whatsapp.index', ['view' => 'setup']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('WhatsApp/Index')
                ->where('view', 'setup')
                ->where('provider', 'none')
                ->has('meta_setup.fields')
                ->where('meta_setup.connected', false)
                ->where('meta_setup.can_manage_meta', true)
                ->where('meta_setup.onboarding_status', 'not_started')
                ->where('meta_setup.values.business_display_name', $workspace->name)
                ->where('meta_setup.autofilled_from', 'workspace_brand')
                ->where('meta_setup.brand_profile.business_display_name', $workspace->name));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->put(route('whatsapp.setup'), [
                'enabled' => true,
                'credentials' => [
                    'business_display_name' => 'Vibgyor Holidays',
                    'business_phone' => '+919889995999',
                    'business_category' => 'TRAVEL',
                ],
            ])
            ->assertRedirect(route('whatsapp.index', ['view' => 'setup']))
            ->assertSessionHas('success');

        $svc = app(WorkspaceIntegrationService::class);
        $this->assertFalse($svc->hasWhatsappMeta($workspace));
        $this->assertSame('none', $svc->whatsappProvider($workspace));

        $row = $svc->getRecord($workspace, 'whatsapp_meta');
        $this->assertSame('disconnected', $row->status);
        $this->assertSame('incomplete', $row->credential('onboarding_status'));
        $this->assertSame('+919889995999', $row->credential('business_phone'));
        $this->assertSame(32, strlen((string) $row->credential('verify_token')));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('whatsapp.index', ['view' => 'setup']))
            ->assertInertia(fn ($page) => $page
                ->where('meta_setup.onboarding_status', 'incomplete')
                ->where('meta_setup.connected', false));
    }

    public function test_workspace_owner_connects_own_meta_credentials(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        Http::fake([
            'graph.facebook.com/v25.0/1234567890*' => Http::response([
                'display_phone_number' => '+91 98899 95999',
                'verified_name' => 'Vibgyor Holidays',
                'id' => '1234567890',
            ], 200),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->put(route('whatsapp.setup'), [
                'enabled' => true,
                'credentials' => [
                    'business_display_name' => 'Vibgyor Holidays',
                    'business_phone' => '+919889995999',
                    'phone_number_id' => '1234567890',
                    'waba_id' => '1330391715353047',
                    'access_token' => 'owner-token',
                    'app_secret' => 'app-secret',
                    'verify_token' => '',
                    'api_version' => 'v25.0',
                ],
            ])
            ->assertRedirect(route('whatsapp.index', ['view' => 'setup']))
            ->assertSessionHas('success');

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), 'graph.facebook.com/v25.0/1234567890')
            && $request->hasHeader('Authorization', 'Bearer owner-token'));

        $svc = app(WorkspaceIntegrationService::class);
        $this->assertSame('meta', $svc->whatsappProvider($workspace));
        $cfg = $svc->whatsappMetaConfig($workspace);
        $this->assertSame('1234567890', $cfg['phone_number_id']);
        $this->assertSame('app-secret', $cfg['app_secret']);
        $this->assertNotEmpty($cfg['verify_token']);

        $row = $svc->get($workspace, 'whatsapp_meta');
        $this->assertSame('connected', $row->credential('onboarding_status'));
        $this->assertSame('Vibgyor Holidays', $row->credential('verified_name'));
    }

    public function test_meta_rejected_credentials_keep_workspace_disconnected(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token.', 'code' => 190],
            ], 401),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->put(route('whatsapp.setup'), [
                'credentials' => [
                    'business_display_name' => 'Vibgyor Holidays',
                    'business_phone' => '+919889995999',
                    'phone_number_id' => '1234567890',
                    'access_token' => 'bad-token',
                ],
            ])
            ->assertRedirect(route('whatsapp.index', ['view' => 'setup']))
            ->assertSessionHas('error');

        $svc = app(WorkspaceIntegrationService::class);
        $this->assertSame('none', $svc->whatsappProvider($workspace));
        $row = $svc->getRecord($workspace, 'whatsapp_meta');
        $this->assertSame('error', $row->status);
        $this->assertStringContainsString('190', (string) $row->last_error);
    }

    public function test_platform_env_credentials_are_never_used_for_a_workspace(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        config([
            'services.meta.whatsapp_phone_number_id' => '820466781144818',
            'services.meta.whatsapp_access_token' => 'platform-token',
            'services.meta.whatsapp_verify_token' => 'platform-verify',
            'services.zavu.key' => 'platform-zavu-key',
        ]);

        $svc = app(WorkspaceIntegrationService::class);
        $this->assertNull($svc->whatsappMetaConfig($workspace));
        $this->assertSame('none', $svc->whatsappProvider($workspace));
        $this->assertSame('none', app(ChannelCampaignService::class)->provider($workspace, 'whatsapp'));
    }

    public function test_cannot_send_whatsapp_when_workspace_not_connected(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        Http::fake();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.conversations.start'), [
                'phone' => '+919876543210',
                'body' => 'Hello',
            ])
            ->assertRedirect(route('whatsapp.index', ['view' => 'setup']))
            ->assertSessionHas('error');

        $this->assertSame(0, WhatsappConversation::query()->count());

        $conversation = WhatsappConversation::query()->create([
            'workspace_id' => $workspace->id,
            'phone' => '+919333344444',
            'status' => 'open',
            'window_expires_at' => now()->addHours(12),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.conversations.reply', $conversation), [
                'body' => 'Thanks',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, WhatsappMessage::query()->count());
        Http::assertNothingSent();
    }

    public function test_can_save_whatsapp_template(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.templates.store'), [
                'name' => 'welcome_hi',
                'body' => 'Hi {{name}} from {{brand}}',
                'category' => 'utility',
                'language' => 'en',
                'wa_status' => 'ready',
            ])
            ->assertRedirect(route('whatsapp.index', ['view' => 'templates']));

        $tpl = ChannelMessageTemplate::query()->first();
        $this->assertSame('whatsapp', $tpl->channel);
        $this->assertSame('utility', $tpl->category);
        $this->assertSame('ready', $tpl->wa_status);
        $this->assertSame('welcome_hi', $tpl->name);
    }

    public function test_can_submit_whatsapp_template_to_meta(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        app(WorkspaceIntegrationService::class)->upsert($workspace, 'whatsapp_meta', [
            'phone_number_id' => '123',
            'waba_id' => 'waba-99',
            'access_token' => 'token',
            'verify_token' => 'verify',
            'api_version' => 'v25.0',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'id' => 'tpl_meta_1',
                'status' => 'PENDING',
                'category' => 'UTILITY',
            ], 200),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.templates.store'), [
                'name' => 'Rankway Hello',
                'body' => 'Hi {{name}}, welcome to {{brand}}.',
                'category' => 'utility',
                'language' => 'en_US',
                'wa_status' => 'draft',
                'submit_to_meta' => true,
            ])
            ->assertRedirect(route('whatsapp.index', ['view' => 'templates']))
            ->assertSessionHas('success');

        $tpl = ChannelMessageTemplate::query()->first();
        $this->assertSame('rankway_hello', $tpl->name);
        $this->assertSame('pending', $tpl->wa_status);
        $this->assertSame('meta:tpl_meta_1', $tpl->subject);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'graph.facebook.com/v25.0/waba-99/message_templates')
                && $request['name'] === 'rankway_hello'
                && $request['category'] === 'UTILITY'
                && $request['components'][0]['type'] === 'BODY';
        });
    }

    public function test_can_start_conversation_with_own_meta_number(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $this->connectMeta($workspace);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.START']]], 200),
        ]);

        CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Ravi',
            'phone' => '+919876543210',
            'stage' => 'new',
            'source' => 'manual',
        ]);

        $lead = CrmLead::query()->first();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.conversations.start'), [
                'crm_lead_id' => $lead->id,
                'body' => 'Hello {{name}}',
            ])
            ->assertRedirect();

        $conversation = WhatsappConversation::query()->first();
        $this->assertNotNull($conversation);
        $this->assertSame('+919876543210', $conversation->phone);
        $this->assertSame(1, WhatsappMessage::query()->where('direction', 'outbound')->count());
        $this->assertStringContainsString('Hello Ravi', WhatsappMessage::query()->first()->body);
    }

    public function test_local_numbers_get_default_country_code(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $this->connectMeta($workspace);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.LOCAL']]], 200),
        ]);

        CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Sunil',
            'phone' => '98765 43210',
            'stage' => 'new',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.conversations.start'), [
                'crm_lead_id' => CrmLead::query()->first()->id,
                'body' => 'Hello',
            ])
            ->assertRedirect();

        $this->assertSame('+919876543210', WhatsappConversation::query()->first()->phone);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages') && $request['to'] === '919876543210');

        $this->assertSame('919876543210', MetaWhatsAppCloudService::internationalDigits('098765 43210'));
        $this->assertSame('14155550100', MetaWhatsAppCloudService::internationalDigits('+1 415 555 0100'));
        $this->assertSame('447700900123', MetaWhatsAppCloudService::internationalDigits('0044 7700 900123'));
        $this->assertSame('919876543210', MetaWhatsAppCloudService::internationalDigits('919876543210'));
    }

    public function test_zavu_webhook_ingests_inbound_message(): void
    {
        [, $workspace] = $this->memberWithWorkspace();

        $this->postJson(route('webhooks.zavu', $workspace), [
            'event' => 'message.inbound',
            'channel' => 'whatsapp',
            'from' => '+919111122222',
            'text' => 'I want a demo',
            'id' => 'msg_inbound_1',
            'conversationId' => 'conv_abc',
        ])->assertOk();

        $conversation = WhatsappConversation::query()->where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($conversation);
        $this->assertSame(1, $conversation->unread_count);
        $this->assertTrue($conversation->windowOpen());
        $this->assertSame('I want a demo', WhatsappMessage::query()->first()->body);
        $this->assertSame('inbound', WhatsappMessage::query()->first()->direction);
    }

    public function test_reply_marks_thread_and_sends(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $this->connectMeta($workspace);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.REPLY']]], 200),
        ]);

        $conversation = WhatsappConversation::query()->create([
            'workspace_id' => $workspace->id,
            'phone' => '+919333344444',
            'contact_name' => 'Asha',
            'status' => 'open',
            'unread_count' => 2,
            'window_expires_at' => now()->addHours(12),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.conversations.reply', $conversation), [
                'body' => 'Thanks, we will call you.',
            ])
            ->assertRedirect();

        $this->assertSame(1, $conversation->messages()->count());
        $this->assertSame('outbound', $conversation->messages()->first()->direction);
        $this->assertSame('sent', $conversation->messages()->first()->status);
    }

    public function test_meta_cloud_api_is_preferred_whatsapp_provider(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->put(route('integrations.update', 'whatsapp_meta'), [
                'enabled' => true,
                'credentials' => [
                    'phone_number_id' => '1234567890',
                    'waba_id' => 'waba_1',
                    'access_token' => 'meta_token_secret',
                    'app_secret' => 'app_secret_value',
                    'verify_token' => 'atlas_verify_token',
                    'api_version' => 'v21.0',
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('meta', app(WorkspaceIntegrationService::class)->whatsappProvider($workspace));
        $this->assertSame('meta', app(ChannelCampaignService::class)->provider($workspace, 'whatsapp'));

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messaging_product' => 'whatsapp',
                'contacts' => [['wa_id' => '919876543210']],
                'messages' => [['id' => 'wamid.TEST123']],
            ], 200),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.conversations.start'), [
                'phone' => '+919876543210',
                'contact_name' => 'Ravi',
                'body' => 'Hello from Meta',
            ])
            ->assertRedirect();

        $msg = WhatsappMessage::query()->first();
        $this->assertSame('wamid.TEST123', $msg->provider_message_id);
        $this->assertSame('meta', $msg->meta['provider'] ?? null);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'graph.facebook.com/v21.0/1234567890/messages')
                && $request['type'] === 'text'
                && $request['to'] === '919876543210';
        });
    }

    private function enableEmbeddedSignup(): void
    {
        config([
            'services.meta.app_id' => '111',
            'services.meta.app_secret' => 'platform-app-secret',
            'services.meta.whatsapp_es_config_id' => 'cfg-1',
            'services.meta.whatsapp_api_version' => 'v25.0',
            'services.meta.whatsapp_webhook_verify_token' => 'app-verify',
        ]);
    }

    public function test_embedded_signup_connects_customer_number(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $this->enableEmbeddedSignup();

        Http::fake([
            'graph.facebook.com/v25.0/oauth/access_token*' => Http::response(['access_token' => 'customer-biz-token'], 200),
            'graph.facebook.com/v25.0/555000111/subscribed_apps' => Http::response(['success' => true], 200),
            'graph.facebook.com/v25.0/777000222/register' => Http::response(['success' => true], 200),
            'graph.facebook.com/v25.0/777000222*' => Http::response([
                'display_phone_number' => '+91 90000 11111',
                'verified_name' => 'Acme Travels',
            ], 200),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('whatsapp.index', ['view' => 'setup']))
            ->assertInertia(fn ($page) => $page
                ->where('meta_setup.embedded_signup.enabled', true)
                ->where('meta_setup.embedded_signup.app_id', '111')
                ->where('meta_setup.embedded_signup.config_id', 'cfg-1'));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.embedded-signup'), [
                'code' => 'auth-code-xyz',
                'waba_id' => '555000111',
                'phone_number_id' => '777000222',
            ])
            ->assertRedirect(route('whatsapp.index', ['view' => 'setup']))
            ->assertSessionHas('success');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth/access_token')
            && str_contains($r->url(), 'code=auth-code-xyz')
            && str_contains($r->url(), 'client_id=111'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '555000111/subscribed_apps')
            && $r->hasHeader('Authorization', 'Bearer customer-biz-token'));
        Http::assertSent(fn ($r) => str_contains($r->url(), '777000222/register')
            && strlen((string) $r['pin']) === 6);

        $svc = app(WorkspaceIntegrationService::class);
        $this->assertSame('meta', $svc->whatsappProvider($workspace));
        $cfg = $svc->whatsappMetaConfig($workspace);
        $this->assertSame('777000222', $cfg['phone_number_id']);
        $this->assertSame('555000111', $cfg['waba_id']);
        $this->assertSame('customer-biz-token', $cfg['access_token']);

        $row = $svc->get($workspace, 'whatsapp_meta');
        $this->assertSame('777000222', $row->external_id);
        $this->assertSame('embedded_signup', $row->credential('connected_via'));
        $this->assertSame('Acme Travels', $row->credential('verified_name'));
        $this->assertSame('+919000011111', $row->credential('business_phone'));
    }

    public function test_embedded_signup_asks_for_pin_when_registration_fails(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $this->enableEmbeddedSignup();

        Http::fake([
            'graph.facebook.com/v25.0/oauth/access_token*' => Http::response(['access_token' => 'customer-biz-token'], 200),
            'graph.facebook.com/v25.0/555000111/subscribed_apps' => Http::response(['success' => true], 200),
            'graph.facebook.com/v25.0/777000222/register' => Http::sequence()
                ->push(['error' => ['code' => 133005, 'message' => 'Two step verification PIN Mismatch']], 400)
                ->push(['success' => true], 200),
            'graph.facebook.com/v25.0/777000222*' => Http::response(['display_phone_number' => '+91 90000 11111'], 200),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.embedded-signup'), [
                'code' => 'auth-code-xyz',
                'waba_id' => '555000111',
                'phone_number_id' => '777000222',
            ])
            ->assertSessionHas('error');

        $svc = app(WorkspaceIntegrationService::class);
        $this->assertSame('none', $svc->whatsappProvider($workspace));
        $this->assertSame('needs_pin', $svc->getRecord($workspace, 'whatsapp_meta')->credential('onboarding_status'));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.register-number'), ['pin' => '246810'])
            ->assertSessionHas('success');

        $this->assertSame('meta', $svc->whatsappProvider($workspace));
        Http::assertSent(fn ($r) => str_contains($r->url(), '777000222/register') && $r['pin'] === '246810');
    }

    public function test_embedded_signup_rejects_number_owned_by_another_workspace(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        [, $other] = $this->memberWithWorkspace();
        $this->enableEmbeddedSignup();

        app(WorkspaceIntegrationService::class)->upsert($other, 'whatsapp_meta', [
            'phone_number_id' => '777000222',
            'access_token' => 'other-token',
            'verify_token' => 'v',
        ]);

        Http::fake([
            'graph.facebook.com/v25.0/oauth/access_token*' => Http::response(['access_token' => 'customer-biz-token'], 200),
            '*' => Http::response(['success' => true], 200),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.embedded-signup'), [
                'code' => 'auth-code-xyz',
                'waba_id' => '555000111',
                'phone_number_id' => '777000222',
            ])
            ->assertSessionHas('error');

        $this->assertSame('none', app(WorkspaceIntegrationService::class)->whatsappProvider($workspace));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'subscribed_apps'));
    }

    public function test_app_level_webhook_routes_inbound_to_workspace_by_phone_number_id(): void
    {
        [, $first] = $this->memberWithWorkspace();
        [, $second] = $this->memberWithWorkspace();
        $this->enableEmbeddedSignup();

        $svc = app(WorkspaceIntegrationService::class);
        $svc->upsert($first, 'whatsapp_meta', ['phone_number_id' => '100', 'access_token' => 't', 'verify_token' => 'v']);
        $svc->upsert($second, 'whatsapp_meta', ['phone_number_id' => '200', 'access_token' => 't', 'verify_token' => 'v']);

        $this->get('/webhooks/meta/whatsapp?hub.mode=subscribe&hub.verify_token=app-verify&hub.challenge=ok-1')
            ->assertOk()
            ->assertSee('ok-1');
        $this->get('/webhooks/meta/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=x')
            ->assertForbidden();

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-2',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => '200'],
                        'contacts' => [['profile' => ['name' => 'Neha'], 'wa_id' => '919555500000']],
                        'messages' => [[
                            'from' => '919555500000',
                            'id' => 'wamid.APP1',
                            'type' => 'text',
                            'text' => ['body' => 'Price?'],
                        ]],
                    ],
                ]],
            ]],
        ];
        $raw = json_encode($payload);

        $send = fn (string $signature) => $this->call('POST', route('webhooks.meta.whatsapp.app'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Hub-Signature-256' => $signature,
        ], $raw);

        $send('sha256=bad')->assertStatus(401);
        $send('sha256='.hash_hmac('sha256', $raw, 'platform-app-secret'))->assertOk();

        $this->assertSame(0, WhatsappConversation::query()->where('workspace_id', $first->id)->count());
        $conversation = WhatsappConversation::query()->where('workspace_id', $second->id)->first();
        $this->assertNotNull($conversation);
        $this->assertSame('Neha', $conversation->contact_name);
    }

    public function test_meta_status_webhook_marks_delivery_and_failure_reason(): void
    {
        [, $workspace] = $this->memberWithWorkspace();
        [, $other] = $this->memberWithWorkspace();
        $this->enableEmbeddedSignup();
        app(WorkspaceIntegrationService::class)->upsert($workspace, 'whatsapp_meta', ['phone_number_id' => '300', 'access_token' => 't', 'verify_token' => 'v']);

        $conversation = WhatsappConversation::query()->create(['workspace_id' => $workspace->id, 'phone' => '+919111100000']);
        $delivered = $conversation->messages()->create(['direction' => 'outbound', 'body' => 'Hi', 'status' => 'sent', 'provider_message_id' => 'wamid.OK']);
        $failed = $conversation->messages()->create(['direction' => 'outbound', 'body' => 'Hi', 'status' => 'sent', 'provider_message_id' => 'wamid.BAD']);
        $foreign = WhatsappConversation::query()->create(['workspace_id' => $other->id, 'phone' => '+919111100001'])
            ->messages()->create(['direction' => 'outbound', 'body' => 'Hi', 'status' => 'sent', 'provider_message_id' => 'wamid.FOREIGN']);

        $statuses = [
            ['id' => 'wamid.OK', 'status' => 'delivered'],
            ['id' => 'wamid.OK', 'status' => 'read'],
            ['id' => 'wamid.OK', 'status' => 'delivered'],
            ['id' => 'wamid.BAD', 'status' => 'failed', 'errors' => [[
                'code' => 131042,
                'title' => 'Business eligibility payment issue',
                'error_data' => ['details' => 'Message failed to send because there were one or more errors related to your payment method.'],
            ]]],
            ['id' => 'wamid.FOREIGN', 'status' => 'failed', 'errors' => [['code' => 131026, 'title' => 'Undeliverable']]],
        ];
        $raw = json_encode([
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'waba-3',
                'changes' => array_map(fn ($status) => [
                    'field' => 'messages',
                    'value' => ['metadata' => ['phone_number_id' => '300'], 'statuses' => [$status]],
                ], $statuses),
            ]],
        ]);

        $this->call('POST', route('webhooks.meta.whatsapp.app'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'platform-app-secret'),
        ], $raw)->assertOk();

        $this->assertSame('read', $delivered->fresh()->status);
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertStringContainsString('131042', $failed->fresh()->error_message);
        $this->assertStringContainsString('payment method', $failed->fresh()->error_message);
        $this->assertSame('sent', $foreign->fresh()->status);
    }

    public function test_workspace_webhook_ignores_other_workspace_numbers(): void
    {
        [, $workspace] = $this->memberWithWorkspace();

        app(WorkspaceIntegrationService::class)->upsert($workspace, 'whatsapp_meta', [
            'phone_number_id' => '100',
            'access_token' => 'token',
            'verify_token' => 'v',
            'app_secret' => 'hubsecret',
        ]);

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'metadata' => ['phone_number_id' => '999'],
                        'messages' => [['from' => '919000000000', 'id' => 'wamid.X', 'type' => 'text', 'text' => ['body' => 'Not yours']]],
                    ],
                ]],
            ]],
        ];
        $raw = json_encode($payload);

        $this->call('POST', route('webhooks.meta.whatsapp', $workspace), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $raw, 'hubsecret'),
        ], $raw)->assertOk();

        $this->assertSame(0, WhatsappConversation::query()->count());
    }

    public function test_meta_webhook_verify_and_inbound(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        app(WorkspaceIntegrationService::class)->upsert($workspace, 'whatsapp_meta', [
            'phone_number_id' => '999',
            'access_token' => 'token',
            'verify_token' => 'my-verify',
            'app_secret' => 'hubsecret',
        ]);

        $this->get('/webhooks/meta/whatsapp/'.$workspace->id.'?hub.mode=subscribe&hub.verify_token=my-verify&hub.challenge=challenge-123')
            ->assertOk()
            ->assertSee('challenge-123');

        $payload = [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'contacts' => [[
                            'profile' => ['name' => 'Priya'],
                            'wa_id' => '919000011111',
                        ]],
                        'messages' => [[
                            'from' => '919000011111',
                            'id' => 'wamid.IN1',
                            'timestamp' => '1710000000',
                            'type' => 'text',
                            'text' => ['body' => 'Hi Meta'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $raw = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'hubsecret');

        $this->call(
            'POST',
            route('webhooks.meta.whatsapp', $workspace),
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X-Hub-Signature-256' => $signature,
            ],
            $raw
        )->assertOk();

        $conversation = WhatsappConversation::query()->where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($conversation);
        $this->assertSame('Priya', $conversation->contact_name);
        $this->assertSame('Hi Meta', WhatsappMessage::query()->first()->body);
    }
}
