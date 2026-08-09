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
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

    public function test_can_start_conversation_in_sandbox(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

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

        $this->assertSame('meta', app(\App\Services\Integrations\WorkspaceIntegrationService::class)->whatsappProvider($workspace));
        $this->assertSame('meta', app(\App\Services\Channels\ChannelCampaignService::class)->provider($workspace, 'whatsapp'));

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response([
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

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            return str_contains($request->url(), 'graph.facebook.com/v21.0/1234567890/messages')
                && $request['type'] === 'text'
                && $request['to'] === '919876543210';
        });
    }

    public function test_meta_webhook_verify_and_inbound(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        app(\App\Services\Integrations\WorkspaceIntegrationService::class)->upsert($workspace, 'whatsapp_meta', [
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
