<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('workspaces.index', absolute: false));

        $user = User::query()->where('email', 'test@example.com')->first();
        $this->assertNotNull($user);
        $this->assertFalse($user->workspaces()->exists());
    }

    public function test_new_user_is_pushed_to_create_workspace_after_register(): void
    {
        $user = User::factory()->create(['is_superadmin' => false]);

        $this->actingAs($user)
            ->get(route('seo.index'))
            ->assertRedirect(route('workspaces.index'));

        $this->actingAs($user)
            ->get(route('workspaces.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Workspaces/Index')
                ->where('onboarding', true)
                ->has('workspaces', 0));
    }
}
