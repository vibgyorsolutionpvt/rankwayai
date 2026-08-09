<?php

namespace Tests\Feature;

use App\Enums\WorkspaceRole;
use App\Jobs\ProcessMediaAssetJob;
use App\Models\MediaAsset;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\ImageVariantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function memberWithWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create();
        $workspace->users()->attach($user->id, ['role' => WorkspaceRole::Owner->value]);

        return [$user, $workspace];
    }

    public function test_upload_list_tag_folder_soft_delete_flow(): void
    {
        Storage::fake('public');
        Queue::fake();
        config(['media.disk' => 'public']);

        [$user, $workspace] = $this->memberWithWorkspace();

        $file = UploadedFile::fake()->image('hero.jpg', 1200, 800);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->post(route('media.store'), [
                'files' => [$file],
                'folder' => 'Campaigns',
                'tags' => 'hero, social',
            ])
            ->assertRedirect();

        $asset = MediaAsset::query()->first();
        $this->assertNotNull($asset);
        $this->assertSame('Campaigns', $asset->folder);
        $this->assertSame(['hero', 'social'], $asset->tags);
        $this->assertNotEmpty($asset->url());
        Storage::disk('public')->assertExists($asset->path);
        Queue::assertPushed(ProcessMediaAssetJob::class);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->get(route('media.index', ['folder' => 'Campaigns', 'tag' => 'hero', 'q' => 'hero']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Media/Index')
                ->has('assets', 1)
                ->where('assets.0.cdn_url', $asset->url())
                ->where('disk', 'public'));

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->patch(route('media.update', $asset), [
                'folder' => 'Brand',
                'tags' => 'logo, primary',
            ])
            ->assertRedirect();

        $asset->refresh();
        $this->assertSame('Brand', $asset->folder);
        $this->assertSame(['logo', 'primary'], $asset->tags);

        $this->actingAs($user)
            ->withSession(['active_workspace_id' => $workspace->id])
            ->delete(route('media.destroy', $asset))
            ->assertRedirect();

        $this->assertSoftDeleted($asset);
        Storage::disk('public')->assertExists($asset->path);
    }

    public function test_variant_job_creates_compressed_thumb(): void
    {
        Storage::fake('public');
        config(['media.disk' => 'public']);

        [$user, $workspace] = $this->memberWithWorkspace();

        $file = UploadedFile::fake()->image('wide.png', 1400, 900);
        $path = $file->store('media/'.$workspace->id, 'public');

        $asset = MediaAsset::query()->create([
            'workspace_id' => $workspace->id,
            'uploaded_by' => $user->id,
            'disk' => 'public',
            'path' => $path,
            'original_name' => 'wide.png',
            'mime_type' => 'image/png',
            'size' => 1000,
            'status' => 'processing',
        ]);

        (new ProcessMediaAssetJob($asset->id))->handle(app(ImageVariantService::class));

        $asset->refresh();
        $this->assertSame('ready', $asset->status);
        $this->assertArrayHasKey('thumb', $asset->variants ?? []);
        Storage::disk('public')->assertExists($asset->variants['thumb']);
    }
}
