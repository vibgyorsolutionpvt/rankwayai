<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\AiUsageLog;
use App\Models\ChannelCampaign;
use App\Models\ChannelCampaignRecipient;
use App\Models\CrmLead;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSubscription;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_whatsapp_campaign_sends_in_sandbox(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

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
        $this->assertSame('sandbox', $campaign->provider);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertSame(1, ChannelCampaignRecipient::query()->where('status', 'sent')->count());
        $this->assertSame('contacted', CrmLead::query()->first()->stage);
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

        $settings = \App\Models\WorkspaceAiSetting::query()->where('workspace_id', $workspace->id)->first();
        $account = \App\Models\BillingAccount::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($account);
        $this->assertSame(500, (int) $account->topup_credits);

        $recharge = \App\Models\CreditRecharge::query()->where('workspace_id', $workspace->id)->first();
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

        $recharge = \App\Models\CreditRecharge::query()->create([
            'workspace_id' => $workspace->id,
            'billing_account_id' => app(\App\Services\Billing\BillingAccountService::class)->account($user)->id,
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
        $account = \App\Models\BillingAccount::query()->where('user_id', $user->id)->first();
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
