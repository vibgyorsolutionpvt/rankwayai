<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Jobs\ProcessMediaAssetJob;
use App\Models\MediaAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class MediaLibraryController extends Controller
{
    use ResolvesWorkspace;

    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request);

        $query = $workspace->mediaAssets()->latest();

        if ($search = trim((string) $request->query('q', ''))) {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('original_name', 'like', '%'.$search.'%')
                    ->orWhere('folder', 'like', '%'.$search.'%')
                    ->orWhere('tags', 'like', '%'.$search.'%');
            });
        }

        if ($folder = $request->query('folder')) {
            if ($folder === 'Unsorted') {
                $query->where(function ($builder) {
                    $builder->whereNull('folder')->orWhere('folder', '');
                });
            } else {
                $query->where('folder', $folder);
            }
        }

        if ($tag = trim((string) $request->query('tag', ''))) {
            $query->where('tags', 'like', '%"'.$tag.'"%');
        }

        $assets = $query->get()->map->toLibraryArray();

        $folders = $workspace->mediaAssets()
            ->select('folder')
            ->distinct()
            ->pluck('folder')
            ->map(fn ($folder) => $folder ?: 'Unsorted')
            ->unique()
            ->sort()
            ->values();

        $tags = $workspace->mediaAssets()
            ->whereNotNull('tags')
            ->pluck('tags')
            ->flatten()
            ->filter()
            ->unique()
            ->sort()
            ->values();

        return Inertia::render('Media/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'assets' => $assets,
            'folders' => $folders,
            'tags' => $tags,
            'filters' => [
                'q' => $request->query('q', ''),
                'folder' => $request->query('folder', ''),
                'tag' => $request->query('tag', ''),
            ],
            'disk' => config('media.disk'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,gif,webp,pdf,mp4'],
            'folder' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'string', 'max:500'],
        ]);

        $disk = config('media.disk', 'public');
        $tags = $this->parseTags($request->input('tags'));

        foreach ($request->file('files', []) as $file) {
            $path = $file->store('media/'.$workspace->id, $disk);

            $asset = MediaAsset::query()->create([
                'workspace_id' => $workspace->id,
                'uploaded_by' => $request->user()->id,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
                'folder' => $request->input('folder') ?: null,
                'tags' => $tags,
                'status' => str_starts_with((string) $file->getMimeType(), 'image/')
                    ? 'processing'
                    : 'ready',
            ]);

            if (str_starts_with((string) $asset->mime_type, 'image/')) {
                ProcessMediaAssetJob::dispatch($asset->id);
            }
        }

        return back()->with('success', 'Upload complete');
    }

    public function update(Request $request, MediaAsset $media): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($media->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'folder' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'string', 'max:500'],
        ]);

        $media->update([
            'folder' => $data['folder'] ?: null,
            'tags' => $this->parseTags($data['tags'] ?? ''),
        ]);

        return back()->with('success', 'Asset updated');
    }

    public function destroy(Request $request, MediaAsset $media): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($media->workspace_id === $workspace->id, 404);

        // Soft delete keeps files for recovery; force-delete can purge later.
        $media->delete();

        return back()->with('success', 'Asset moved to trash');
    }

    /** @return list<string> */
    private function parseTags(?string $raw): array
    {
        if (! $raw) {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($tag) => trim($tag))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
