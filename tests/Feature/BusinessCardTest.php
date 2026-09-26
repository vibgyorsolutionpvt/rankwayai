<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\BusinessCard;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BusinessCardTest extends TestCase
{
    use RefreshDatabase;

    private function ownerWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['name' => 'Vibgyor Holidays']);
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        return [$user, $workspace];
    }

    public function test_owner_can_create_and_publish_business_card(): void
    {
        [$user, $workspace] = $this->ownerWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('studio.cards.store'), [
                'title' => 'Anil card',
                'template_key' => 'classic',
                'status' => 'published',
                'person_name' => 'Anil',
                'person_title' => 'Founder',
                'phone' => '+919876543210',
                'email' => 'anil@example.com',
                'is_public' => true,
            ])
            ->assertRedirect();

        $card = BusinessCard::query()->where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($card);
        $this->assertNotEmpty($card->share_token);
        $this->assertSame('published', $card->status);

        $this->get(route('studio.cards.public', $card->share_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Studio/Cards/Public')
                ->where('card.person_name', 'Anil'));

        $this->assertSame(1, $card->fresh()->views_count);

        $this->postJson(route('studio.cards.public.track', $card->share_token), [
            'event' => 'whatsapp',
        ])->assertOk();

        $this->assertSame(1, $card->fresh()->whatsapp_clicks);
    }

    public function test_draft_card_is_not_publicly_accessible(): void
    {
        [$user, $workspace] = $this->ownerWorkspace();

        $card = BusinessCard::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Hidden',
            'template_key' => 'classic',
            'status' => 'draft',
            'is_public' => true,
        ]);

        $this->get(route('studio.cards.public', $card->share_token))->assertNotFound();
    }

    public function test_workspace_isolation_for_cards(): void
    {
        [$userA, $workspaceA] = $this->ownerWorkspace();
        [$userB, $workspaceB] = $this->ownerWorkspace();

        $card = BusinessCard::query()->create([
            'workspace_id' => $workspaceA->id,
            'created_by' => $userA->id,
            'title' => 'A card',
            'template_key' => 'modern',
            'status' => 'published',
        ]);

        $this->actingAs($userB)
            ->withSession(['active_workspace_id' => $workspaceB->id])
            ->get(route('studio.cards.edit', $card))
            ->assertNotFound();
    }
}
