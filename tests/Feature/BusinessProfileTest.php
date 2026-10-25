<?php

namespace Tests\Feature;

use App\Models\BrandKit;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Access\ModuleAccess;
use App\Support\BusinessTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BusinessProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_hiding_business_module_removes_it_from_owner_nav(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->put(route('workspaces.modules.update', $workspace), [
                'modules' => ['today', 'settings', 'brand'],
                'inherit_all' => false,
            ])
            ->assertRedirect();

        $access = app(ModuleAccess::class);
        $keys = $access->userEnabledKeys($user, $workspace->fresh());
        $this->assertNotContains('business', $keys);
        $this->assertContains('settings', $keys);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('business.edit'))
            ->assertRedirect();
    }

    public function test_business_profile_page_loads(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['name' => 'Vibgyor Holidays']);
        $workspace->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('business.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Business/Profile')
                ->where('activeWorkspace.name', 'Vibgyor Holidays'));
    }

    public function test_owner_can_save_expanded_business_profile(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['name' => 'Vibgyor Holidays']);
        $workspace->users()->attach($user->id, ['role' => 'owner']);

        $response = $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->patch(route('workspaces.profile.update', $workspace), [
                'business_type' => 'travel_agency',
                'tagline' => 'Unforgettable journeys',
                'description' => 'Custom holiday packages across India.',
                'city' => 'Lucknow',
                'state' => 'Uttar Pradesh',
                'country' => 'India',
                'postal_code' => '226001',
                'address' => 'Hazratganj',
                'phone' => '+919876543210',
                'whatsapp' => '+919876543210',
                'email' => 'hello@vibgyor.test',
                'website' => 'https://vibgyor.test',
                'services' => "Domestic tours\nInternational tours",
                'products' => "Kashmir 6N\nGoa 4N",
                'target_audience' => 'Families',
                'working_hours' => 'Mon–Sat 10:00–19:00',
                'social_links' => [
                    'instagram' => 'https://instagram.com/vibgyor',
                    'facebook' => '',
                ],
            ]);

        $response->assertRedirect();
        $workspace->refresh();

        $this->assertSame('travel_agency', $workspace->business_type);
        $this->assertSame('Travel Agency', $workspace->industry);
        $this->assertSame('Lucknow', $workspace->city);
        $this->assertSame(['Domestic tours', 'International tours'], $workspace->services);
        $this->assertSame('https://instagram.com/vibgyor', $workspace->social_links['instagram']);
        $this->assertTrue($workspace->hasBusinessProfile());
        $this->assertSame('Travel Agency', $workspace->businessProfile()['business_type_label']);
    }

    public function test_owner_can_upload_and_replace_business_logo_for_generated_posters(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['name' => 'Vibgyor Holidays']);
        $workspace->users()->attach($user->id, ['role' => 'owner']);
        Storage::fake('public');

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->from(route('business.edit'))
            ->post(route('business.logo.store'), [
                'logo' => UploadedFile::fake()->image('business-logo.png'),
            ])
            ->assertRedirect(route('business.edit'))
            ->assertSessionHas('success', 'Business logo saved and ready for generated posters.');

        $brand = BrandKit::query()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertTrue($brand->is_active);
        $previousLogo = $brand->logo_path;
        Storage::disk('public')->assertExists($previousLogo);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->from(route('business.edit'))
            ->post(route('business.logo.store'), [
                'logo' => UploadedFile::fake()->image('updated-logo.png'),
            ])
            ->assertRedirect(route('business.edit'))
            ->assertSessionHas('success');

        $brand->refresh();
        $this->assertNotSame($previousLogo, $brand->logo_path);
        Storage::disk('public')->assertMissing($previousLogo);
        Storage::disk('public')->assertExists($brand->logo_path);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('business.edit'))
            ->assertInertia(fn ($page) => $page
                ->component('Business/Profile')
                ->where('activeWorkspace.logo_url', Storage::disk('public')->url($brand->logo_path)));
    }

    public function test_other_business_type_requires_custom_industry(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => 'owner']);

        $response = $this->actingAs($user)
            ->from(route('business.edit'))
            ->patch(route('workspaces.profile.update', $workspace), [
                'business_type' => 'other',
                'industry' => '',
                'city' => 'Mumbai',
            ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors('industry');
    }

    public function test_business_types_catalog_covers_spec_verticals(): void
    {
        $keys = BusinessTypes::keys();
        $this->assertContains('travel_agency', $keys);
        $this->assertContains('real_estate', $keys);
        $this->assertContains('other', $keys);
    }
}
