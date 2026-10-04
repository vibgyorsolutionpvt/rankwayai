<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\ChannelCampaign;
use App\Models\ChannelMessageTemplate;
use App\Models\CrmLead;
use App\Models\CrmLeadGroup;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use App\Services\Channels\ChannelCampaignService;
use App\Services\Integrations\WorkspaceIntegrationService;
use App\Services\WhatsApp\MetaWhatsAppCloudService;
use App\Services\WhatsApp\WhatsAppConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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
                ->where('view', 'setup')
                ->has('conversations')
                ->has('templates')
                ->has('campaigns'));

        $this->connectMeta($workspace);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('whatsapp.index'))
            ->assertInertia(fn ($page) => $page->where('view', 'conversations'));
    }

    public function test_campaign_csv_import_merges_leads_by_phone_and_selects_imported_contacts(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $workspace->forceFill([
            'crm_lead_custom_fields' => [
                ['key' => 'client', 'label' => 'Client'],
            ],
        ])->save();

        $existing = CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Old name',
            'phone' => '+91 98899 95999',
            'email' => 'old@example.com',
            'stage' => 'new',
            'source' => 'manual',
            'custom_fields' => ['client' => 'Existing'],
        ]);

        $csv = UploadedFile::fake()->createWithContent(
            'contacts.csv',
            "name,phone,email,company,client\n".
            "Anil,+919889995999,,Example Co,Returning\n".
            "Meera,+919876543210,meera@example.com,New Co,New client\n".
            "Invalid,,invalid-email,,Skipped\n",
        );

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.campaigns.lead-groups.store'), ['name' => 'October customers'])
            ->assertRedirect(route('whatsapp.index', ['view' => 'campaigns']))
            ->assertSessionHas('success', 'Contact group “October customers” created. Import a CSV or Excel file to add contacts.');

        $group = CrmLeadGroup::query()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('completed', $group->status);
        $this->assertSame(0, $group->leads()->count());

        $this->post(route('whatsapp.campaigns.lead-groups.store'), ['name' => 'October customers'])
            ->assertSessionHasErrors('name');

        $this->post(route('whatsapp.campaigns.lead-groups.import', $group), ['csv_file' => $csv])
            ->assertRedirect(route('whatsapp.index', ['view' => 'campaigns']))
            ->assertSessionHas('success', 'Importing contacts into “October customers”. Existing contacts in the group stay as they are.');

        $this->assertSame(2, CrmLead::query()->where('workspace_id', $workspace->id)->count());
        $existing->refresh();
        $this->assertSame('Anil', $existing->name);
        $this->assertSame('old@example.com', $existing->email);
        $this->assertSame('Example Co', $existing->company);
        $this->assertSame('Returning', $existing->custom_fields['client']);

        $newLead = CrmLead::query()->where('phone', '+919876543210')->firstOrFail();
        $this->assertSame('csv_import', $newLead->source);
        $this->assertSame('New client', $newLead->custom_fields['client']);

        $group->refresh();
        $this->assertSame('completed', $group->status);
        $this->assertSame(1, $group->created_count);
        $this->assertSame(1, $group->updated_count);
        $this->assertSame(1, $group->skipped_count);
        $this->assertEqualsCanonicalizing(
            [$existing->id, $newLead->id],
            $group->leads()->pluck('crm_leads.id')->all(),
        );

        $this->get(route('whatsapp.index', ['view' => 'campaigns']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('view', 'campaigns')
                ->where('importedGroupId', $group->id)
                ->has('leadGroups', 1)
                ->where('leadGroups.0.name', 'October customers')
                ->has('leadImportFields', 1)
                ->where('leadImportFields.0.key', 'client'));
    }

    public function test_campaign_contact_group_import_accepts_twenty_thousand_rows(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $rows = ['name,phone'];
        for ($index = 0; $index < 20000; $index++) {
            $rows[] = 'Contact '.$index.',+1555'.str_pad((string) $index, 7, '0', STR_PAD_LEFT);
        }
        $csv = UploadedFile::fake()->createWithContent('large-group.csv', implode("\n", $rows));

        $group = CrmLeadGroup::query()->create([
            'workspace_id' => $workspace->id,
            'name' => '20k contacts',
            'status' => 'completed',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.campaigns.lead-groups.import', $group), ['csv_file' => $csv])
            ->assertRedirect(route('whatsapp.index', ['view' => 'campaigns']));

        $group->refresh();
        $this->assertSame('completed', $group->status);
        $this->assertSame(20000, $group->total_rows);
        $this->assertSame(20000, $group->leads()->count());
        $this->assertSame(20000, $group->created_count);
        $this->assertSame(0, $group->skipped_count);
    }

    public function test_csv_import_adds_to_existing_group_and_keeps_members_on_bad_file(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        [, $otherWorkspace] = $this->memberWithWorkspace();
        $group = CrmLeadGroup::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Regulars',
            'status' => 'completed',
        ]);
        $import = fn (string $content) => $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.campaigns.lead-groups.import', $group), [
                'csv_file' => UploadedFile::fake()->createWithContent('contacts.csv', $content),
            ]);

        $import("name,phone\nAsha,+919100000001\n")->assertRedirect();
        $import("name,phone\nAsha,+919100000001\nBina,+919100000002\n")->assertRedirect();

        $group->refresh();
        $this->assertSame(2, $group->leads()->count());
        $this->assertSame(1, $group->created_count);
        $this->assertSame(1, $group->updated_count);

        $import("email\nnobody@example.com\n")->assertRedirect();
        $group->refresh();
        $this->assertSame('completed', $group->status);
        $this->assertStringContainsString('name and phone', $group->error_message);
        $this->assertSame(2, $group->leads()->count());

        $foreign = CrmLeadGroup::query()->create([
            'workspace_id' => $otherWorkspace->id,
            'name' => 'Theirs',
            'status' => 'completed',
        ]);
        $this->post(route('whatsapp.campaigns.lead-groups.import', $foreign), [
            'csv_file' => UploadedFile::fake()->createWithContent('contacts.csv', "name,phone\nX,+919100000009\n"),
        ])->assertNotFound();
    }

    public function test_excel_and_untyped_csv_files_import_into_group(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $group = CrmLeadGroup::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Excel people',
            'status' => 'completed',
        ]);

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Name', 'Phone', 'Email'],
            ['Kiran', 919100000011, 'kiran@example.com'],
            ['Lata', '+91 91000 00012', null],
        ]);
        $xlsxPath = tempnam(sys_get_temp_dir(), 'wa').'.xlsx';
        (new Xlsx($spreadsheet))->save($xlsxPath);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.campaigns.lead-groups.import', $group), [
                'csv_file' => new UploadedFile($xlsxPath, 'contacts.xlsx', null, null, true),
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $group->refresh();
        $this->assertSame('completed', $group->status, (string) $group->error_message);
        $this->assertSame(2, $group->created_count);
        $this->assertSame(
            ['919100000011', '+91 91000 00012'],
            $group->leads()->orderBy('crm_leads.id')->pluck('phone')->all(),
        );

        $this->post(route('whatsapp.campaigns.lead-groups.import', $group), [
            'csv_file' => UploadedFile::fake()->createWithContent('more.csv', "name,phone\nMohan,+919100000013\n")
                ->mimeType('application/octet-stream'),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(3, $group->leads()->count());

        $this->post(route('whatsapp.campaigns.lead-groups.import', $group), [
            'csv_file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
        ])->assertSessionHasErrors('csv_file');
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
        $this->assertSame('Reply STOP to opt out', $tpl->components['footer']);
    }

    public function test_stop_reply_pauses_whatsapp_messages_for_one_month(): void
    {
        [, $workspace] = $this->memberWithWorkspace();
        $conversationService = app(WhatsAppConversationService::class);

        $message = $conversationService->ingestInbound($workspace, [
            'from' => '+919111122222',
            'text' => '  stop! ',
            'id' => 'msg_stop_1',
        ]);

        $this->assertNotNull($message);
        $conversation = $message->conversation()->firstOrFail();
        $this->assertTrue($conversation->optedOut());
        $this->assertTrue(
            $conversation->opted_out_until->between(now()->addMonth()->subSecond(), now()->addMonth()->addSecond()),
        );

        $result = $conversationService->sendOutbound(
            $workspace,
            $conversation,
            'This should not send.',
        );
        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('opted out', strtolower($result['error']));
        $this->assertSame(0, WhatsappMessage::query()->where('direction', 'outbound')->count());

        $this->assertTrue(WhatsappConversation::phoneIsOptedOut($workspace->id, '919111122222'));
        $this->assertFalse(WhatsappConversation::phoneIsOptedOut($workspace->id, '+919111122223'));
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

    public function test_meta_template_components_include_header_footer_and_buttons(): void
    {
        $components = app(MetaWhatsAppCloudService::class)->templateToMetaComponents(
            'Hi {{name}}',
            [
                'header' => ['format' => 'TEXT', 'text' => 'Welcome {{brand}}'],
                'footer' => 'Reply STOP to opt out',
                'buttons' => [
                    ['type' => 'URL', 'text' => 'Visit site', 'url' => 'https://example.com'],
                    ['type' => 'QUICK_REPLY', 'text' => 'Help'],
                ],
            ],
        );

        $this->assertSame('HEADER', $components[0]['type']);
        $this->assertSame('Welcome {{1}}', $components[0]['text']);
        $this->assertSame(['header_text' => ['RankwayAI']], $components[0]['example']);
        $this->assertSame('BODY', $components[1]['type']);
        $this->assertSame('FOOTER', $components[2]['type']);
        $this->assertSame('BUTTONS', $components[3]['type']);
        $this->assertSame('https://example.com', $components[3]['buttons'][0]['url']);
    }

    public function test_can_create_and_send_whatsapp_media_header_template(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        Storage::fake('local');

        app(WorkspaceIntegrationService::class)->upsert($workspace, 'whatsapp_meta', [
            'phone_number_id' => '123',
            'waba_id' => 'waba-99',
            'app_id' => 'app-44',
            'access_token' => 'token',
            'verify_token' => 'verify',
            'api_version' => 'v25.0',
        ]);

        Http::fake([
            'graph.facebook.com/*' => Http::sequence()
                ->push(['id' => 'upload-session'], 200)
                ->push(['h' => 'meta-header-handle'], 200)
                ->push(['id' => 'wa-media-id'], 200)
                ->push(['id' => 'meta-template-id', 'status' => 'PENDING'], 200)
                ->push(['id' => 'wa-media-id-for-send'], 200)
                ->push(['messages' => [['id' => 'wamid.MEDIA']]], 200),
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.templates.store'), [
                'name' => 'sale_announcement',
                'body' => 'Hi {{name}}, our offer is live.',
                'category' => 'marketing',
                'language' => 'en_US',
                'wa_status' => 'draft',
                'submit_to_meta' => true,
                'header_format' => 'IMAGE',
                'header_media' => UploadedFile::fake()->image('offer.png'),
                'footer' => 'Reply STOP to opt out',
                'buttons' => [
                    ['type' => 'URL', 'text' => 'Shop now', 'url' => 'https://example.com/shop'],
                    ['type' => 'QUICK_REPLY', 'text' => 'Tell me more'],
                ],
            ])
            ->assertRedirect(route('whatsapp.index', ['view' => 'templates']))
            ->assertSessionHas('success');

        $template = ChannelMessageTemplate::query()->firstOrFail();
        $this->assertSame('IMAGE', $template->components['header']['format']);
        $this->assertSame('wa-media-id', $template->components['header']['media_id']);
        Storage::disk('local')->assertExists($template->components['header']['media_path']);
        $this->get(route('whatsapp.templates.media', $template))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
        $this->assertSame('Reply STOP to opt out', $template->components['footer']);
        $this->assertCount(2, $template->components['buttons']);
        $clientHeader = $template->toArrayBrief()['components']['header'];
        $this->assertArrayNotHasKey('media_path', $clientHeader);
        $this->assertArrayNotHasKey('media_id', $clientHeader);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/message_templates')
                && $request['components'][0] === [
                    'type' => 'HEADER',
                    'format' => 'IMAGE',
                    'example' => ['header_handle' => ['meta-header-handle']],
                ]
                && $request['components'][2] === [
                    'type' => 'FOOTER',
                    'text' => 'Reply STOP to opt out',
                ]
                && $request['components'][3]['buttons'][0]['url'] === 'https://example.com/shop';
        });

        $result = app(MetaWhatsAppCloudService::class)->sendText(
            $workspace,
            '+919876543210',
            $template->body,
            $template,
            true,
        );

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages')
            && $request['template']['components'][0]['type'] === 'header'
            && $request['template']['components'][0]['parameters'][0]['image']['id'] === 'wa-media-id-for-send');
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

    public function test_campaign_thread_appears_in_inbox_only_after_delivery(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $this->connectMeta($workspace);
        Http::fake([
            'graph.facebook.com/*' => Http::sequence()
                ->push(['messages' => [['id' => 'wamid.CAMP1']]], 200)
                ->push(['messages' => [['id' => 'wamid.CAMP2']]], 200)
                ->push(['error' => ['message' => 'Invalid number', 'code' => 131026]], 400),
        ]);

        $lead = CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Ravi',
            'phone' => '+919876543210',
            'stage' => 'new',
            'source' => 'manual',
        ]);
        $template = ChannelMessageTemplate::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'name' => 'diwali_offer',
            'channel' => 'whatsapp',
            'language' => 'en_US',
            'wa_status' => 'approved',
            'body' => 'Hello {{name}}',
        ]);
        $campaign = ChannelCampaign::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'name' => 'Diwali',
            'channel' => 'whatsapp',
            'whatsapp_template_id' => $template->id,
            'body' => $template->body,
            'status' => 'draft',
            'recipient_count' => 1,
        ]);
        $campaign->recipients()->create(['crm_lead_id' => $lead->id, 'to' => $lead->phone, 'status' => 'pending']);
        $campaign->recipients()->create(['to' => '+919876500001', 'status' => 'pending']);
        $campaign->recipients()->create(['to' => '+919876500002', 'status' => 'pending']);

        app(ChannelCampaignService::class)->send($campaign);
        $this->assertSame(0, WhatsappConversation::query()->count());

        app(WhatsAppConversationService::class)->applyDeliveryStatus($workspace, [
            'id' => 'wamid.CAMP1', 'status' => 'delivered', 'error' => null, 'error_meta' => null,
        ]);

        $conversation = WhatsappConversation::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame($user->id, $conversation->assigned_user_id);
        $this->assertSame($lead->id, $conversation->crm_lead_id);
        $message = $conversation->messages()->sole();
        $this->assertSame('outbound', $message->direction);
        $this->assertSame('Hello Ravi', $message->body);
        $this->assertSame('wamid.CAMP1', $message->provider_message_id);
        $this->assertSame('delivered', $message->status);
        $this->assertSame($campaign->id, $message->channel_campaign_id);
        $this->assertSame('Diwali', $message->toClientArray()['campaign_name']);
        $this->assertSame($user->name, $message->toClientArray()['user_name']);

        app(WhatsAppConversationService::class)->applyDeliveryStatus($workspace, [
            'id' => 'wamid.CAMP1', 'status' => 'read', 'error' => null, 'error_meta' => null,
        ]);
        $this->assertSame('read', $message->fresh()->status);
        $this->assertSame(1, WhatsappMessage::query()->count());
        $this->assertSame(1, WhatsappConversation::query()->count());

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('whatsapp.index', ['view' => 'conversations', 'campaign' => $campaign->id]))
            ->assertInertia(fn ($page) => $page->where('inboxTotal', 1)->where('conversations.0.id', $conversation->id));
        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('whatsapp.index', ['view' => 'conversations', 'q' => 'nobody']))
            ->assertInertia(fn ($page) => $page->where('inboxTotal', 0)->has('conversations', 0));
        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('whatsapp.index', ['view' => 'conversations', 'q' => '98765 43210']))
            ->assertInertia(fn ($page) => $page->where('inboxTotal', 1));
    }

    public function test_conversation_can_be_assigned_and_filtered_by_owner(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();
        $teammate = User::factory()->create();
        $workspace->users()->attach($teammate->id, ['role' => WorkspaceRole::Editor->value]);
        $outsider = User::factory()->create();

        $mine = WhatsappConversation::query()->create(['workspace_id' => $workspace->id, 'phone' => '+919000000001', 'assigned_user_id' => $user->id]);
        $open = WhatsappConversation::query()->create(['workspace_id' => $workspace->id, 'phone' => '+919000000002']);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.conversations.assign', $open), ['user_id' => $teammate->id])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame($teammate->id, $open->fresh()->assigned_user_id);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.conversations.assign', $open), ['user_id' => $outsider->id])
            ->assertSessionHasErrors('user_id');
        $this->assertSame($teammate->id, $open->fresh()->assigned_user_id);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('whatsapp.index', ['view' => 'conversations', 'inbox' => 'mine']))
            ->assertInertia(fn ($page) => $page
                ->where('inbox', 'mine')
                ->has('conversations', 1)
                ->where('conversations.0.id', $mine->id)
                ->has('teamMembers', 2));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('whatsapp.conversations.assign', $open), ['user_id' => null])
            ->assertRedirect();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('whatsapp.index', ['view' => 'conversations', 'inbox' => 'unassigned']))
            ->assertInertia(fn ($page) => $page
                ->has('conversations', 1)
                ->where('conversations.0.id', $open->id));
    }

    public function test_members_only_see_their_own_or_unassigned_chats_and_handoff_moves_history(): void
    {
        [$owner, $workspace] = $this->memberWithWorkspace();
        $rajeev = User::factory()->create();
        $priya = User::factory()->create();
        $workspace->users()->attach($rajeev->id, ['role' => WorkspaceRole::Editor->value]);
        $workspace->users()->attach($priya->id, ['role' => WorkspaceRole::Editor->value]);

        $rajeevChat = WhatsappConversation::query()->create(['workspace_id' => $workspace->id, 'phone' => '+919000000011', 'assigned_user_id' => $rajeev->id]);
        $rajeevChat->messages()->create(['direction' => 'inbound', 'body' => 'First question', 'status' => 'received']);
        $rajeevChat->messages()->create(['direction' => 'outbound', 'body' => 'Rajeev answer', 'status' => 'sent', 'user_id' => $rajeev->id]);
        $priyaChat = WhatsappConversation::query()->create(['workspace_id' => $workspace->id, 'phone' => '+919000000012', 'assigned_user_id' => $priya->id]);
        $openChat = WhatsappConversation::query()->create(['workspace_id' => $workspace->id, 'phone' => '+919000000013']);

        $as = fn (User $user) => $this->actingAs($user)->withSession(['active_workspace_id' => $workspace->id]);

        $as($rajeev)->get(route('whatsapp.index', ['view' => 'conversations', 'conversation' => $priyaChat->id]))
            ->assertInertia(fn ($page) => $page
                ->where('seesAllConversations', false)
                ->has('conversations', 2)
                ->where('activeConversation', null)
                ->where('counts.conversations', 2));
        $as($rajeev)->post(route('whatsapp.conversations.reply', $priyaChat), ['body' => 'Hi'])->assertNotFound();
        $as($rajeev)->post(route('whatsapp.conversations.assign', $priyaChat), ['user_id' => $rajeev->id])->assertNotFound();

        $as($owner)->get(route('whatsapp.index', ['view' => 'conversations']))
            ->assertInertia(fn ($page) => $page->where('seesAllConversations', true)->has('conversations', 3));

        $as($rajeev)->post(route('whatsapp.conversations.assign', $rajeevChat), ['user_id' => $priya->id])
            ->assertRedirect(route('whatsapp.index', ['view' => 'conversations']));
        $this->assertSame($priya->id, $rajeevChat->fresh()->assigned_user_id);

        $as($rajeev)->get(route('whatsapp.index', ['view' => 'conversations', 'conversation' => $rajeevChat->id]))
            ->assertInertia(fn ($page) => $page
                ->has('conversations', 1)
                ->where('conversations.0.id', $openChat->id)
                ->where('activeConversation', null));

        $as($priya)->get(route('whatsapp.index', ['view' => 'conversations', 'conversation' => $rajeevChat->id]))
            ->assertInertia(fn ($page) => $page
                ->where('activeConversation.id', $rajeevChat->id)
                ->has('messages', 2)
                ->where('messages.0.body', 'First question')
                ->where('messages.1.body', 'Rajeev answer'));
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
