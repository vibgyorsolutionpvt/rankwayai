<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkspaceMemberInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_invite_new_email_and_create_user(): void
    {
        Notification::fake();

        $owner = User::factory()->create(['is_superadmin' => false]);
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        $this->actingAs($owner)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('workspaces.members.store', $workspace), [
                'name' => 'Rajeev',
                'email' => 'rathorerajeev303@gmail.com',
                'role' => 'editor',
            ])
            ->assertRedirect();

        $invited = User::query()->where('email', 'rathorerajeev303@gmail.com')->first();
        $this->assertNotNull($invited);
        $this->assertSame('Rajeev', $invited->name);
        $this->assertFalse($invited->is_superadmin);
        $this->assertTrue($workspace->fresh()->hasMember($invited));
        $this->assertSame('editor', $workspace->roleFor($invited)?->value);
    }

    public function test_existing_member_email_is_rejected(): void
    {
        $owner = User::factory()->create(['is_superadmin' => false]);
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($owner->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        $this->actingAs($owner)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('workspaces.members.store', $workspace), [
                'email' => $owner->email,
                'role' => 'editor',
            ])
            ->assertSessionHasErrors('email');
    }
}
