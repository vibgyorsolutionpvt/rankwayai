<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\CrmLead;
use App\Models\CrmLeadActivity;
use App\Models\CrmLeadAttachment;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CrmLeadDetailTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        return [$user, $workspace];
    }

    public function test_lead_show_notes_and_stage_timeline(): void
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
        $this->assertNotNull($lead);
        $this->assertSame(1, CrmLeadActivity::query()->where('type', 'created')->count());

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('crm.show', $lead))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Crm/Show')
                ->where('lead.name', 'Asha')
                ->has('activities', 1)
                ->has('attachments'));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('crm.notes.store', $lead), ['body' => 'Called; interested in Pro plan.'])
            ->assertRedirect();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->patch(route('crm.update', $lead), ['stage' => 'contacted'])
            ->assertRedirect();

        $this->assertSame(3, CrmLeadActivity::query()->where('crm_lead_id', $lead->id)->count());
        $this->assertDatabaseHas('crm_lead_activities', [
            'crm_lead_id' => $lead->id,
            'type' => 'note',
            'body' => 'Called; interested in Pro plan.',
        ]);
        $this->assertSame('contacted', $lead->fresh()->stage);
    }

    public function test_open_whatsapp_links_conversation_to_lead(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $lead = CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Ravi',
            'phone' => '+919111111111',
            'stage' => 'new',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('crm.whatsapp.open', $lead))
            ->assertRedirect(route('whatsapp.index', [
                'view' => 'conversations',
                'conversation' => WhatsappConversation::query()->first()->id,
            ]));

        $conversation = WhatsappConversation::query()->first();
        $this->assertNotNull($conversation);
        $this->assertSame($lead->id, $conversation->crm_lead_id);
        $this->assertSame('+919111111111', $conversation->phone);
        $this->assertDatabaseHas('crm_lead_activities', [
            'crm_lead_id' => $lead->id,
            'type' => 'whatsapp',
        ]);
    }

    public function test_lead_can_upload_download_and_remove_pdf_xls_images(): void
    {
        Storage::fake('public');
        [$user, $workspace] = $this->memberWithWorkspace();

        $lead = CrmLead::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Meera',
            'email' => 'meera@example.com',
            'stage' => 'qualified',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('crm.notes.store', $lead), [
                'body' => 'Sharing proposal pack',
                'files' => [
                    UploadedFile::fake()->create('proposal.pdf', 120, 'application/pdf'),
                    UploadedFile::fake()->create('pricing.xlsx', 80, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
                    UploadedFile::fake()->image('site.jpg', 200, 120),
                ],
            ])
            ->assertRedirect();

        $this->assertSame(3, CrmLeadAttachment::query()->where('crm_lead_id', $lead->id)->count());
        $this->assertDatabaseHas('crm_lead_attachments', [
            'crm_lead_id' => $lead->id,
            'original_name' => 'proposal.pdf',
            'kind' => 'pdf',
        ]);
        $this->assertDatabaseHas('crm_lead_attachments', [
            'crm_lead_id' => $lead->id,
            'original_name' => 'pricing.xlsx',
            'kind' => 'spreadsheet',
        ]);
        $this->assertDatabaseHas('crm_lead_attachments', [
            'crm_lead_id' => $lead->id,
            'original_name' => 'site.jpg',
            'kind' => 'image',
        ]);

        $note = CrmLeadActivity::query()->where('type', 'note')->latest('id')->first();
        $this->assertSame('Sharing proposal pack', $note->body);
        $this->assertCount(3, $note->meta['attachments'] ?? []);

        $pdf = CrmLeadAttachment::query()->where('kind', 'pdf')->first();
        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('crm.attachments.download', [$lead, $pdf]))
            ->assertOk();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->delete(route('crm.attachments.destroy', [$lead, $pdf]))
            ->assertRedirect();

        $this->assertDatabaseMissing('crm_lead_attachments', ['id' => $pdf->id]);
    }
}
