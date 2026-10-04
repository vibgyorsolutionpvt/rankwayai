<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Jobs\SendChannelCampaignJob;
use App\Models\AiUsageLog;
use App\Models\BillingAccount;
use App\Models\ChannelCampaign;
use App\Models\ChannelCampaignRecipient;
use App\Models\ChannelMessageTemplate;
use App\Models\CreditRecharge;
use App\Models\CrmLead;
use App\Models\CrmLeadGroup;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\Workspace;
use App\Models\WorkspaceAiSetting;
use App\Models\WorkspaceSubscription;
use App\Services\Billing\BillingAccountService;
use App\Services\Billing\BillingService;
use App\Services\Billing\UsageMeterService;
use App\Services\Channels\ChannelCampaignService;
use App\Services\Integrations\WorkspaceIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class V2ChannelsCrmBillingTest extends TestCase
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

    private function connectWhatsapp(Workspace $workspace): void
    {
        app(WorkspaceIntegrationService::class)->upsert($workspace, 'whatsapp_meta', [
            'phone_number_id' => '1234567890',
            'access_token' => 'meta_token_secret',
            'verify_token' => 'verify',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.CAMPAIGN']]], 200),
        ]);
    }

    public function test_crm_lead_pipeline(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('crm.store'), [
                'name' => 'Asha',
                'email' => 'asha@example.com',
                'phone' => '+919876543210',
                'value_cents' => 50000,
            ])
            ->assertRedirect();

        $lead = CrmLead::query()->first();
        $this->assertSame('new', $lead->stage);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->patch(route('crm.update', $lead), ['stage' => 'qualified'])
            ->assertRedirect();

        $this->assertSame('qualified', $lead->fresh()->stage);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('crm.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Crm/Index')->has('byStage.qualified', 1));
    }

    public function test_whatsapp_campaign_is_blocked_until_workspace_connects_whatsapp(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        Http::fake();

        CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Ravi',
            'phone' => '+919111111111',
            'stage' => 'new',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('channels.store'), [
                'name' => 'Festive blast',
                'channel' => 'whatsapp',
                'body' => 'Hello from Atlas',
                'delivery' => 'now',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, ChannelCampaign::query()->count());

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('channels.store'), [
                'name' => 'Draft blast',
                'channel' => 'whatsapp',
                'body' => 'Hello from Atlas',
                'delivery' => 'draft',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $draft = ChannelCampaign::query()->first();
        $this->assertSame('draft', $draft->status);

        // Scheduled/queued sends that run after a disconnect fail instead of sending.
        app(ChannelCampaignService::class)->send($draft);
        $draft->refresh();
        $this->assertSame('failed', $draft->status);
        $this->assertSame('none', $draft->provider);
        $this->assertSame(0, $draft->sent_count);
        $this->assertSame('new', CrmLead::query()->first()->stage);
        Http::assertNothingSent();
    }

    public function test_whatsapp_campaign_sends_from_workspace_meta_number(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $this->connectWhatsapp($workspace);

        CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Ravi',
            'phone' => '+919111111111',
            'stage' => 'new',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('channels.store'), [
                'name' => 'Festive blast',
                'channel' => 'whatsapp',
                'body' => 'Hello from Atlas',
                'delivery' => 'now',
            ])
            ->assertRedirect();

        $campaign = ChannelCampaign::query()->first();
        $this->assertSame('sent', $campaign->status);
        $this->assertSame('meta', $campaign->provider);
        $this->assertSame(1, $campaign->sent_count);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'graph.facebook.com/v21.0/1234567890/messages'));
        $this->assertSame(1, ChannelCampaignRecipient::query()->where('status', 'sent')->count());
        $this->assertSame('contacted', CrmLead::query()->first()->stage);
    }

    public function test_whatsapp_campaign_audience_filters_dedupes_and_skips_opted_out(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $this->connectWhatsapp($workspace);
        $workspace->update(['crm_lead_custom_fields' => [['key' => 'city', 'label' => 'City', 'type' => 'text']]]);

        $lead = fn (string $name, string $phone, string $stage, string $source, array $custom = []) => CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'phone' => $phone,
            'stage' => $stage,
            'source' => $source,
            'custom_fields' => $custom,
        ]);
        $lead('Ravi', '+91 91111 11111', 'new', 'website', ['city' => 'Delhi']);
        $lead('Ravi again', '919111111111', 'contacted', 'website', ['city' => 'Delhi']);
        $lead('Stop', '+919222222222', 'new', 'website', ['city' => 'Delhi']);
        $lead('Mumbai', '+919333333333', 'new', 'website', ['city' => 'Mumbai']);
        $lead('Won', '+919444444444', 'won', 'website', ['city' => 'Delhi']);
        $lead('Ads', '+919555555555', 'new', 'ads', ['city' => 'Delhi']);
        WhatsappConversation::query()->create([
            'workspace_id' => $workspace->id,
            'phone' => '+919222222222',
            'opted_out_until' => now()->addMonth(),
        ]);

        $filters = [
            'recipient_mode' => 'all',
            'stages' => ['new', 'contacted', 'won'],
            'sources' => ['website'],
            'custom_field' => 'city',
            'custom_value' => 'Delhi',
        ];

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->postJson(route('whatsapp.campaigns.audience-preview'), $filters)
            ->assertOk()
            ->assertExactJson(['matched' => 4, 'recipients' => 2, 'duplicates' => 1, 'opted_out' => 1]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->postJson(route('whatsapp.campaigns.audience-preview'), ['recipient_mode' => 'all'])
            ->assertOk()
            ->assertJson(['recipients' => 3, 'duplicates' => 1, 'opted_out' => 1]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('channels.store'), [
                'name' => 'Delhi blast',
                'channel' => 'whatsapp',
                'body' => 'Hello',
                'delivery' => 'now',
                ...$filters,
            ])
            ->assertRedirect();

        $campaign = ChannelCampaign::query()->first();
        $this->assertSame(2, $campaign->recipient_count);
        $this->assertEqualsCanonicalizing(
            ['+91 91111 11111', '+919444444444'],
            ChannelCampaignRecipient::query()->pluck('to')->all()
        );
    }

    public function test_whatsapp_campaign_with_no_matching_recipients_is_not_created(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $this->connectWhatsapp($workspace);
        CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Won',
            'phone' => '+919444444444',
            'stage' => 'won',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('channels.store'), [
                'name' => 'Empty',
                'channel' => 'whatsapp',
                'body' => 'Hello',
                'delivery' => 'now',
                'recipient_mode' => 'all',
                'stages' => [],
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, ChannelCampaign::query()->count());
    }

    public function test_whatsapp_campaign_sends_selected_approved_template_with_lead_values(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $this->connectWhatsapp($workspace);

        $lead = CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Ravi',
            'phone' => '+919111111111',
            'stage' => 'new',
            'source' => 'manual',
        ]);
        $template = ChannelMessageTemplate::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'name' => 'welcome_campaign',
            'channel' => 'whatsapp',
            'category' => 'marketing',
            'language' => 'en_US',
            'wa_status' => 'approved',
            'body' => 'Hi {{name}} from {{brand}}',
            'components' => [
                'header' => ['format' => 'TEXT', 'text' => 'Welcome {{name}}'],
            ],
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('channels.store'), [
                'name' => 'Welcome campaign',
                'channel' => 'whatsapp',
                'body' => $template->body,
                'whatsapp_template_id' => $template->id,
                'lead_ids' => [$lead->id],
                'delivery' => 'now',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $campaign = ChannelCampaign::query()->firstOrFail();
        $this->assertSame($template->id, $campaign->whatsapp_template_id);
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(1, $campaign->sent_count);

        Http::assertSent(function ($request) use ($workspace) {
            return str_contains($request->url(), 'graph.facebook.com/v21.0/1234567890/messages')
                && $request['type'] === 'template'
                && $request['template']['name'] === 'welcome_campaign'
                && $request['template']['components'][0]['type'] === 'header'
                && $request['template']['components'][0]['parameters'][0]['text'] === 'Ravi'
                && $request['template']['components'][1]['type'] === 'body'
                && $request['template']['components'][1]['parameters'][0]['text'] === 'Ravi'
                && $request['template']['components'][1]['parameters'][1]['text'] === $workspace->name;
        });
    }

    public function test_whatsapp_campaign_can_target_a_saved_contact_group(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $lead = CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Group lead',
            'phone' => '+919111111111',
            'stage' => 'new',
            'source' => 'csv_import',
        ]);
        $group = CrmLeadGroup::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'name' => 'October customers',
            'status' => 'completed',
        ]);
        $group->leads()->attach($lead->id);
        $template = ChannelMessageTemplate::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'name' => 'welcome_campaign',
            'channel' => 'whatsapp',
            'language' => 'en_US',
            'wa_status' => 'approved',
            'body' => 'Hi {{name}}',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('channels.store'), [
                'name' => 'Group campaign',
                'channel' => 'whatsapp',
                'body' => $template->body,
                'whatsapp_template_id' => $template->id,
                'recipient_mode' => 'group',
                'crm_lead_group_id' => $group->id,
                'delivery' => 'draft',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $campaign = ChannelCampaign::query()->firstOrFail();
        $this->assertSame($group->id, $campaign->crm_lead_group_id);
        $this->assertSame(1, $campaign->recipient_count);
        $this->assertSame($lead->id, $campaign->recipients()->value('crm_lead_id'));
    }

    public function test_saved_group_campaign_sends_in_worker_batches(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $this->connectWhatsapp($workspace);

        $group = CrmLeadGroup::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'name' => 'Batch contacts',
            'status' => 'completed',
        ]);
        $template = ChannelMessageTemplate::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'name' => 'batch_template',
            'channel' => 'whatsapp',
            'language' => 'en_US',
            'wa_status' => 'approved',
            'body' => 'Hello {{name}}',
        ]);
        $now = now();
        $leadRows = [];
        $membershipRows = [];
        $recipientRows = [];
        $campaign = ChannelCampaign::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'name' => 'Batch campaign',
            'channel' => 'whatsapp',
            'whatsapp_template_id' => $template->id,
            'crm_lead_group_id' => $group->id,
            'body' => $template->body,
            'status' => 'sending',
            'provider' => 'meta',
            'recipient_count' => 205,
        ]);
        for ($index = 0; $index < 205; $index++) {
            $leadRows[] = [
                'workspace_id' => $workspace->id,
                'name' => 'Batch lead '.$index,
                'phone' => '+1555'.str_pad((string) $index, 7, '0', STR_PAD_LEFT),
                'stage' => 'new',
                'source' => 'csv_import',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($leadRows, 100) as $chunk) {
            CrmLead::query()->insert($chunk);
        }
        $leads = CrmLead::query()->where('workspace_id', $workspace->id)->get(['id', 'phone']);
        foreach ($leads as $lead) {
            $membershipRows[] = [
                'crm_lead_group_id' => $group->id,
                'crm_lead_id' => $lead->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $recipientRows[] = [
                'channel_campaign_id' => $campaign->id,
                'crm_lead_id' => $lead->id,
                'to' => $lead->phone,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        $group->leads()->sync($leads->modelKeys());
        foreach (array_chunk($recipientRows, 100) as $chunk) {
            ChannelCampaignRecipient::query()->insert($chunk);
        }

        SendChannelCampaignJob::dispatchSync($campaign->id);

        $campaign->refresh();
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(205, $campaign->sent_count);
        $this->assertSame(0, $campaign->recipients()->where('status', 'pending')->count());
        Http::assertSentCount(205);
    }

    public function test_whatsapp_campaign_rejects_unapproved_template(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $template = ChannelMessageTemplate::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'name' => 'pending_template',
            'channel' => 'whatsapp',
            'language' => 'en_US',
            'wa_status' => 'pending',
            'body' => 'Hi {{name}}',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->from(route('whatsapp.index', ['view' => 'campaigns']))
            ->post(route('channels.store'), [
                'name' => 'Pending template campaign',
                'channel' => 'whatsapp',
                'body' => $template->body,
                'whatsapp_template_id' => $template->id,
                'delivery' => 'draft',
            ])
            ->assertRedirect(route('whatsapp.index', ['view' => 'campaigns']))
            ->assertSessionHasErrors('whatsapp_template_id');

        $this->assertSame(0, ChannelCampaign::query()->count());
    }

    public function test_whatsapp_campaign_skips_a_contact_who_replied_stop(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $this->connectWhatsapp($workspace);

        $lead = CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Ravi',
            'phone' => '+919111111111',
            'stage' => 'new',
            'source' => 'manual',
        ]);
        WhatsappConversation::query()->create([
            'workspace_id' => $workspace->id,
            'crm_lead_id' => $lead->id,
            'phone' => '+919111111111',
            'opted_out_until' => now()->addMonth(),
        ]);

        $campaign = ChannelCampaign::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'name' => 'Do not send',
            'channel' => 'whatsapp',
            'body' => 'Hello {{name}}',
            'status' => 'draft',
            'recipient_count' => 1,
        ]);
        $campaign->recipients()->create([
            'crm_lead_id' => $lead->id,
            'to' => '+919111111111',
            'status' => 'pending',
        ]);

        $result = app(ChannelCampaignService::class)->send($campaign);

        $this->assertSame(0, $result['campaign']->sent_count);
        $this->assertSame(1, $result['campaign']->failed_count);
        $this->assertSame('failed', $campaign->recipients()->first()->status);
        $this->assertStringContainsString(
            'opted out',
            strtolower($campaign->recipients()->first()->error_message),
        );
        Http::assertNothingSent();
    }

    public function test_rcs_campaign_sends_in_sandbox(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Priya',
            'phone' => '+919222222222',
            'stage' => 'new',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('channels.store'), [
                'name' => 'RCS launch',
                'channel' => 'rcs',
                'rcs_provider' => 'jio',
                'body' => 'Hi {{name}} from {{brand}}',
                'delivery' => 'now',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $campaign = ChannelCampaign::query()->first();
        $this->assertSame('rcs', $campaign->channel);
        $this->assertSame('jio', $campaign->provider);
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(1, ChannelCampaignRecipient::query()->where('status', 'sent')->count());
    }

    public function test_channels_page_lists_rcs_providers(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('channels.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Channels/Index')
                ->has('rcs_providers')
                ->where('rcs_providers.0.id', 'sandbox'));
    }

    public function test_channel_template_can_be_saved_and_used_in_campaign(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $this->connectWhatsapp($workspace);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('channels.templates.store'), [
                'name' => 'WA hello',
                'channel' => 'whatsapp',
                'body' => 'Hi {{name}} from {{brand}}',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('channel_message_templates', [
            'workspace_id' => $workspace->id,
            'name' => 'WA hello',
            'channel' => 'whatsapp',
        ]);

        CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Ravi',
            'phone' => '+919111111111',
            'stage' => 'new',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('channels.store'), [
                'name' => 'From template',
                'channel' => 'whatsapp',
                'body' => 'Hi {{name}} from {{brand}}',
                'delivery' => 'now',
            ])
            ->assertRedirect();

        $this->assertSame('sent', ChannelCampaign::query()->first()->status);
    }

    public function test_email_campaign_requires_subject(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('channels.store'), [
                'name' => 'Newsletter',
                'channel' => 'email',
                'body' => 'Hello',
                'delivery' => 'draft',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, ChannelCampaign::query()->count());
    }

    public function test_campaign_can_be_scheduled(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $this->connectWhatsapp($workspace);

        CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Neha',
            'phone' => '+919222222222',
            'email' => 'neha@example.com',
            'stage' => 'new',
            'source' => 'manual',
        ]);

        $when = now()->addDay()->format('Y-m-d\TH:i');

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('channels.store'), [
                'name' => 'Tomorrow blast',
                'channel' => 'whatsapp',
                'body' => 'See you tomorrow',
                'delivery' => 'schedule',
                'scheduled_at' => $when,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $campaign = ChannelCampaign::query()->first();
        $this->assertSame('scheduled', $campaign->status);
        $this->assertNotNull($campaign->scheduled_at);
        $this->assertSame(0, $campaign->sent_count);
    }

    public function test_monthly_channel_quota_excludes_whatsapp_sends(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        foreach ([
            ['channel' => 'whatsapp', 'sent_count' => 10000],
            ['channel' => 'email', 'sent_count' => 4],
            ['channel' => 'rcs', 'sent_count' => 3],
        ] as $campaignData) {
            ChannelCampaign::query()->create([
                'workspace_id' => $workspace->id,
                'created_by' => $user->id,
                'name' => ucfirst($campaignData['channel']).' campaign',
                'channel' => $campaignData['channel'],
                'body' => 'Test',
                'status' => 'sent',
                'sent_count' => $campaignData['sent_count'],
            ]);
        }

        $usage = app(UsageMeterService::class)->forWorkspace(
            $workspace,
            app(BillingService::class)->subscription($workspace),
        );

        $this->assertSame(7, $usage['channel_sends']['used']);
        $this->assertSame('Email / RCS sends', $usage['channel_sends']['label']);
    }

    public function test_billing_defaults_to_free_and_plan_change_is_manual(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Billing/Index')
                ->where('subscription.plan', 'free')
                ->where('subscription.status', 'active')
                ->where('market', 'in')
                ->where('can_switch_market', false));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('billing.plan'), ['plan' => 'growth', 'market' => 'in'])
            ->assertRedirect();

        $sub = WorkspaceSubscription::query()->where('workspace_id', $workspace->id)->first();
        $this->assertSame('growth', $sub->plan);
        $this->assertSame('active', $sub->status);
        $this->assertSame('manual', $sub->billing_provider);
        $this->assertSame('in', $sub->billing_market);
        $this->assertSame('INR', $sub->billing_currency);
        $this->assertSame(6999.0, (float) $sub->mrr_amount);
        $this->assertSame(79.0, (float) $sub->mrr_usd);
    }

    public function test_global_market_applies_usd_manually_without_razorpay(): void
    {
        $user = User::factory()->create(['is_superadmin' => true]);
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('billing.plan'), ['plan' => 'starter', 'market' => 'global'])
            ->assertRedirect();

        $sub = WorkspaceSubscription::query()->where('workspace_id', $workspace->id)->first();
        $this->assertSame('starter', $sub->plan);
        $this->assertSame('global', $sub->billing_market);
        $this->assertSame('USD', $sub->billing_currency);
        $this->assertSame(29.0, (float) $sub->mrr_amount);
        $this->assertSame('manual', $sub->billing_provider);
    }

    public function test_regular_client_cannot_force_global_market(): void
    {
        $user = User::factory()->create(['is_superadmin' => false]);
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->withHeader('CF-IPCountry', 'IN')
            ->post(route('billing.plan'), ['plan' => 'starter', 'market' => 'global'])
            ->assertRedirect();

        $sub = WorkspaceSubscription::query()->where('workspace_id', $workspace->id)->first();
        $this->assertSame('in', $sub->billing_market);
        $this->assertSame('INR', $sub->billing_currency);
        $this->assertSame(2499.0, (float) $sub->mrr_amount);
    }

    public function test_razorpay_payment_link_webhook_activates_plan(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace('free');

        $this->postJson(route('webhooks.razorpay'), [
            'event' => 'payment_link.paid',
            'payload' => [
                'payment_link' => [
                    'entity' => [
                        'id' => 'plink_plan_test',
                        'notes' => [
                            'type' => 'plan_checkout',
                            'workspace_id' => (string) $workspace->id,
                            'plan' => 'growth',
                            'market' => 'in',
                            'interval' => 'month',
                        ],
                    ],
                ],
            ],
        ])->assertOk();

        $sub = WorkspaceSubscription::query()->where('workspace_id', $workspace->id)->first();
        $this->assertSame('growth', $sub->plan);
        $this->assertSame('razorpay', $sub->billing_provider);
        $this->assertSame('INR', $sub->billing_currency);
        $this->assertSame(6999.0, (float) $sub->mrr_amount);
    }

    public function test_razorpay_webhook_activates_subscription(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace('free');

        $this->postJson(route('webhooks.razorpay'), [
            'event' => 'subscription.activated',
            'payload' => [
                'subscription' => [
                    'entity' => [
                        'id' => 'sub_test_rzp',
                        'customer_id' => 'cust_test',
                        'notes' => [
                            'workspace_id' => (string) $workspace->id,
                            'plan' => 'starter',
                        ],
                    ],
                ],
            ],
        ])->assertOk();

        $sub = WorkspaceSubscription::query()->where('workspace_id', $workspace->id)->first();
        $this->assertSame('starter', $sub->plan);
        $this->assertSame('razorpay', $sub->billing_provider);
        $this->assertSame('INR', $sub->billing_currency);
        $this->assertSame(2499.0, (float) $sub->mrr_amount);
    }

    public function test_free_plan_blocks_ai_and_channel_send(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace('free');

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('social.compose.ai'), [
                'prompt' => 'Promote our monsoon travel package with family discount',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->from(route('channels.index'))
            ->post(route('channels.store'), [
                'name' => 'Blocked blast',
                'channel' => 'whatsapp',
                'body' => 'Hello',
                'delivery' => 'now',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, ChannelCampaign::query()->count());
    }

    public function test_credit_recharge_applies_manually_without_gateway(): void
    {
        config([
            'services.razorpay.key_id' => null,
            'services.razorpay.key_secret' => null,
        ]);

        [$user, $workspace] = $this->memberWithWorkspace('starter');

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('billing.credits.recharge'), ['pack' => 'in_500', 'market' => 'in'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $settings = WorkspaceAiSetting::query()->where('workspace_id', $workspace->id)->first();
        $account = BillingAccount::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($account);
        $this->assertSame(500, (int) $account->topup_credits);

        $recharge = CreditRecharge::query()->where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($recharge);
        $this->assertSame('paid', $recharge->status);
        $this->assertSame('manual', $recharge->provider);
        $this->assertSame(500, (int) $recharge->credits);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('billing.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Billing/Index')
                ->has('credit_history', 1)
                ->where('credit_history.0.credits', 500)
                ->where('credit_history.0.status', 'paid')
                ->has('ai_history')
                ->has('ai_history.members')
                ->has('ai_history.activities'));
    }

    public function test_billing_ai_history_groups_by_member_and_period(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace('agency');
        $other = User::factory()->create(['name' => 'Riya']);
        $workspace->users()->attach($other->id, ['role' => WorkspaceRole::Editor->value]);

        AiUsageLog::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'action' => 'generate_today',
            'provider' => 'template',
            'tokens' => 0,
            'cost_usd' => 0.01,
            'meta' => [],
        ]);
        $old = AiUsageLog::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $other->id,
            'action' => 'blog_outline',
            'provider' => 'openai',
            'tokens' => 120,
            'cost_usd' => 0.02,
            'meta' => [],
        ]);
        $old->forceFill([
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
        ])->save();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('billing.index', ['history' => 'today']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Billing/Index')
                ->where('history_period', 'today')
                ->where('ai_history.period', 'today')
                ->where('ai_history.totals.events', 1)
                ->where('ai_history.members.0.name', $user->name)
                ->has('ai_history.activities', 1)
                ->where('ai_history.activities.0.member', $user->name));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('billing.index', ['history' => '30d']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('ai_history.period', '30d')
                ->where('ai_history.totals.events', 2)
                ->has('ai_history.members', 2));
    }

    public function test_razorpay_payment_link_webhook_adds_credits(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace('starter');

        $recharge = CreditRecharge::query()->create([
            'workspace_id' => $workspace->id,
            'billing_account_id' => app(BillingAccountService::class)->account($user)->id,
            'user_id' => $user->id,
            'pack_id' => 'in_2000',
            'credits' => 2000,
            'amount' => 699,
            'currency' => 'INR',
            'billing_market' => 'in',
            'status' => 'pending',
            'provider' => 'razorpay',
            'provider_ref' => 'plink_test',
        ]);

        $this->postJson(route('webhooks.razorpay'), [
            'event' => 'payment_link.paid',
            'payload' => [
                'payment_link' => [
                    'entity' => [
                        'id' => 'plink_test',
                        'notes' => [
                            'type' => 'credit_recharge',
                            'recharge_id' => (string) $recharge->id,
                            'workspace_id' => (string) $workspace->id,
                            'credits' => '2000',
                        ],
                    ],
                ],
            ],
        ])->assertOk();

        $this->assertSame('paid', $recharge->fresh()->status);
        $account = BillingAccount::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($account);
        $this->assertSame(2000, (int) $account->topup_credits);
    }

    public function test_yearly_plan_applies_with_year_interval(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('billing.plan'), [
                'plan' => 'starter',
                'market' => 'in',
                'interval' => 'year',
            ])
            ->assertRedirect();

        $sub = WorkspaceSubscription::query()->where('workspace_id', $workspace->id)->first();
        $this->assertSame('starter', $sub->plan);
        $this->assertSame('year', $sub->billing_interval);
        $this->assertSame(24990.0, (float) $sub->mrr_amount);
        $this->assertSame('INR', $sub->billing_currency);
        $this->assertNotNull($sub->current_period_ends_at);
        $this->assertTrue($sub->current_period_ends_at->greaterThan(now()->addMonths(10)));
    }

    public function test_billing_page_can_preview_yearly_prices(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('billing.index', ['interval' => 'year']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Billing/Index')
                ->where('interval', 'year')
                ->where('plans.1.id', 'starter')
                ->where('plans.1.price', 24990)
                ->where('plans.1.interval', 'year'));
    }
}
