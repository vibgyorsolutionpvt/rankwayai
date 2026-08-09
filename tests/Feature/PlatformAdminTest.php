<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_lands_on_admin_dashboard(): void
    {
        $admin = User::factory()->create([
            'is_superadmin' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertRedirect(route('admin.dashboard'));

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/Dashboard'));
    }

    public function test_client_cannot_open_admin_dashboard(): void
    {
        $client = User::factory()->create([
            'is_superadmin' => false,
        ]);

        $this->actingAs($client)
            ->get(route('home'))
            ->assertRedirect(route('today'));

        $this->actingAs($client)
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }
}
