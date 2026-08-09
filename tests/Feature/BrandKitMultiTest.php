<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\BrandKit;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandKitMultiTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

        return [$user, $workspace];
    }

    public function test_workspace_can_have_multiple_brand_kits_with_one_active(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('brand.edit'))
            ->assertOk();

        $first = BrandKit::query()->where('workspace_id', $workspace->id)->first();
        $this->assertTrue((bool) $first->is_active);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('brand.store'), [
                'name' => 'Festival',
                'make_active' => true,
            ])
            ->assertRedirect();

        $festival = BrandKit::query()->where('name', 'Festival')->first();
        $this->assertTrue((bool) $festival->is_active);
        $this->assertFalse((bool) $first->fresh()->is_active);
        $this->assertSame($festival->id, $workspace->fresh()->resolveBrandKit()?->id);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('brand.activate', $first))
            ->assertRedirect();

        $this->assertTrue((bool) $first->fresh()->is_active);
        $this->assertFalse((bool) $festival->fresh()->is_active);
    }
}
