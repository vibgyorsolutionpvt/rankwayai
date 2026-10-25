<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Models\Brochure;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BrochureTest extends TestCase
{
    use RefreshDatabase;

    private function ownerWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'name' => 'Vibgyor Holidays',
            'industry' => 'Travel Agency',
            'city' => 'Lucknow',
            'tagline' => 'Unforgettable journeys',
            'services' => ['Domestic tours', 'International tours'],
        ]);
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);
        app(BillingService::class)->changePlan($workspace, 'starter', 'active');

        return [$user, $workspace];
    }

    public function test_owner_can_create_edit_and_publish_brochure(): void
    {
        [$user, $workspace] = $this->ownerWorkspace();

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('studio.brochures.store'), [
                'title' => 'Kashmir brochure',
                'template_key' => 'travel',
                'status' => 'published',
                'headline' => 'Kashmir packages',
                'subheadline' => '6N / 5D',
                'is_public' => true,
            ])
            ->assertRedirect();

        $brochure = Brochure::query()->where('workspace_id', $workspace->id)->first();
        $this->assertNotNull($brochure);
        $this->assertNotEmpty($brochure->sections);
        $this->assertSame('published', $brochure->status);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('studio.brochures.update', $brochure), [
                'title' => 'Kashmir brochure',
                'template_key' => 'travel',
                'status' => 'published',
                'headline' => 'Kashmir packages',
                'subheadline' => 'Updated',
                'is_public' => true,
                'sections' => [
                    [
                        'key' => 'about',
                        'title' => 'About',
                        'body' => 'We craft Kashmir trips.',
                        'items' => "Guided tours\nHotel stays",
                        'enabled' => true,
                    ],
                ],
            ])
            ->assertRedirect();

        $brochure->refresh();
        $this->assertSame('Updated', $brochure->subheadline);
        $this->assertSame(['Guided tours', 'Hotel stays'], $brochure->sections[0]['items']);

        $this->get(route('studio.brochures.public', $brochure->share_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Studio/Brochures/Public')
                ->where('brochure.headline', 'Kashmir packages'));

        $this->assertSame(1, $brochure->fresh()->views_count);
    }

    public function test_draft_brochure_is_not_public(): void
    {
        [$user, $workspace] = $this->ownerWorkspace();

        $brochure = Brochure::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $user->id,
            'title' => 'Hidden',
            'template_key' => 'agency',
            'status' => 'draft',
            'sections' => [],
            'is_public' => true,
        ]);

        $this->get(route('studio.brochures.public', $brochure->share_token))->assertNotFound();
    }

    public function test_brochure_workspace_isolation(): void
    {
        [$userA, $workspaceA] = $this->ownerWorkspace();
        [$userB, $workspaceB] = $this->ownerWorkspace();

        $brochure = Brochure::query()->create([
            'workspace_id' => $workspaceA->id,
            'created_by' => $userA->id,
            'title' => 'A only',
            'template_key' => 'agency',
            'status' => 'published',
            'sections' => [],
        ]);

        $this->actingAs($userB)
            ->withSession(['active_workspace_id' => $workspaceB->id])
            ->get(route('studio.brochures.edit', $brochure))
            ->assertNotFound();
    }

    public function test_custom_colors_are_saved_and_applied(): void
    {
        [$user, $workspace] = $this->ownerWorkspace();
        $brochure = Brochure::query()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Colors',
            'template_key' => 'agency',
            'status' => 'published',
            'sections' => [],
        ]);

        $payload = [
            'title' => 'Colors',
            'template_key' => 'agency',
            'status' => 'published',
            'is_public' => true,
            'sections' => [],
        ];
        $session = ['active_workspace_id' => $workspace->id];

        $this->actingAs($user)->withSession($session)
            ->post(route('studio.brochures.update', $brochure), $payload + [
                'colors' => ['primary_color' => '#e11d48', 'secondary_color' => '', 'accent_color' => '#FACC15'],
            ])
            ->assertSessionHasNoErrors();

        $brochure->refresh();
        $this->assertSame(['primary_color' => '#e11d48', 'accent_color' => '#FACC15'], $brochure->colors);
        $this->get(route('studio.brochures.public', $brochure->share_token))
            ->assertOk()
            ->assertSee('#E11D48', false);

        $this->actingAs($user)->withSession($session)
            ->post(route('studio.brochures.update', $brochure), $payload + [
                'colors' => ['primary_color' => 'red'],
            ])
            ->assertSessionHasErrors('colors.primary_color');
    }

    public function test_brochure_logo_upload_replace_and_pdf(): void
    {
        Storage::fake('public');
        [$user, $workspace] = $this->ownerWorkspace();
        $brochure = Brochure::query()->create([
            'workspace_id' => $workspace->id,
            'title' => 'Logo',
            'template_key' => 'agency',
            'status' => 'published',
            'sections' => [],
        ]);
        $payload = ['title' => 'Logo', 'template_key' => 'agency', 'status' => 'published', 'is_public' => true, 'sections' => []];
        $session = ['active_workspace_id' => $workspace->id];

        $this->actingAs($user)->withSession($session)
            ->post(route('studio.brochures.update', $brochure), $payload + [
                'logo' => UploadedFile::fake()->image('logo.png', 200, 80),
            ])
            ->assertSessionHasNoErrors();

        $brochure->refresh();
        $this->assertNotNull($brochure->logo_path);
        Storage::disk('public')->assertExists($brochure->logo_path);
        $this->actingAs($user)->withSession($session)
            ->get(route('studio.brochures.pdf', $brochure))->assertOk();

        $old = $brochure->logo_path;
        $this->actingAs($user)->withSession($session)
            ->post(route('studio.brochures.update', $brochure), $payload + ['remove_logo' => true]);
        $this->assertNull($brochure->fresh()->logo_path);
        Storage::disk('public')->assertMissing($old);
    }
}
