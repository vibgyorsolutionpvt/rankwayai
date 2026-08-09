<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_login_user_logout(): void
    {
        Notification::fake();

        $register = $this->postJson('/api/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
        ]);

        $register
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'ada@example.com')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email']]]);

        Notification::assertSentTo(User::first(), VerifyEmail::class);

        $token = $register->json('data.token');

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.email', 'ada@example.com');

        $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'Password1!',
        ])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user']]);

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk();

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_invalid_login(): void
    {
        User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'Password1!',
        ]);

        $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_forgot_and_reset_password(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'ada@example.com',
            'password' => 'Password1!',
        ]);

        $this->postJson('/api/forgot-password', [
            'email' => 'ada@example.com',
        ])->assertOk();

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->postJson('/api/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'NewPassword1!',
                'password_confirmation' => 'NewPassword1!',
            ])->assertOk();

            return true;
        });

        $this->postJson('/api/login', [
            'email' => 'ada@example.com',
            'password' => 'NewPassword1!',
        ])->assertOk();
    }

    public function test_verify_email(): void
    {
        $user = User::factory()->unverified()->create();

        $url = URL::temporarySignedRoute(
            'api.verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->getJson($url)->assertOk();

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_update_profile(): void
    {
        $user = User::factory()->create(['name' => 'Old']);

        $this->actingAs($user, 'sanctum')
            ->patchJson('/api/user', ['name' => 'New'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New');
    }
}
