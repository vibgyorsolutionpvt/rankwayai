<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\BrandKit;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
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
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

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

    public function test_brand_kit_saves_extended_style_fields(): void
    {
        [$user, $workspace] = $this->memberWithWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('brand.edit'))
            ->assertOk();

        $kit = BrandKit::query()->where('workspace_id', $workspace->id)->firstOrFail();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('brand.update', $kit), [
                'name' => 'Default',
                'primary_color' => '#0E9F90',
                'secondary_color' => '#0B1220',
                'accent_color' => '#F59E0B',
                'font_family' => 'Plus Jakarta Sans',
                'heading_font' => 'Playfair Display',
                'brand_tone' => 'luxury',
                'default_header' => 'Vibgyor Holidays',
                'default_footer' => 'Terms apply.',
                'default_cta_label' => 'Book now',
            ])
            ->assertRedirect();

        $kit->refresh();
        $this->assertSame('#F59E0B', $kit->accent_color);
        $this->assertSame('Playfair Display', $kit->heading_font);
        $this->assertSame('luxury', $kit->brand_tone);
        $this->assertSame('Vibgyor Holidays', $kit->default_header);
        $this->assertSame('Playfair Display', $kit->styleTokens()['heading_font']);
    }
}
