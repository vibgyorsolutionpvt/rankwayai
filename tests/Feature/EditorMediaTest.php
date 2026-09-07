<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EditorMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_upload_editor_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $workspace = \App\Models\Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => \App\Enums\WorkspaceRole::Owner->value]);

        $response = $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->postJson(route('editor.images.store'), [
                'image' => UploadedFile::fake()->image('photo.jpg', 800, 600),
            ]);

        $response->assertOk()->assertJsonStructure(['url']);
        $url = $response->json('url');
        $this->assertIsString($url);
        $this->assertStringContainsString('/storage/editor/'.$user->id.'/', $url);
    }

    public function test_guest_cannot_upload_editor_image(): void
    {
        $this->post(route('editor.images.store'), [
            'image' => UploadedFile::fake()->image('photo.jpg'),
        ])->assertRedirect(route('login'));
    }
}
