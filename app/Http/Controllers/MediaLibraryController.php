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

        $currentFolder = trim((string) $request->query('folder', ''));
        $search = trim((string) $request->query('q', ''));
        $tag = trim((string) $request->query('tag', ''));

        $folderStats = $workspace->mediaAssets()
            ->selectRaw("COALESCE(NULLIF(folder, ''), 'Unsorted') as name, COUNT(*) as count")
            ->groupBy('name')
            ->orderBy('name')
            ->get()
            ->map(fn ($row) => [
                'name' => (string) $row->name,
                'count' => (int) $row->count,
            ])
            ->values()
            ->all();

        $query = $workspace->mediaAssets()->latest();

        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder
                    ->where('original_name', 'like', '%'.$search.'%')
                    ->orWhere('folder', 'like', '%'.$search.'%')
                    ->orWhere('tags', 'like', '%'.$search.'%');
            });
        }

        if ($tag !== '') {
            $query->where('tags', 'like', '%"'.$tag.'"%');
        }

        // Explorer mode: at root show only unsorted files; inside a folder show that folder.
        // Search across everything when q is set (still respect folder if chosen).
        if ($currentFolder !== '') {
            if ($currentFolder === 'Unsorted') {
                $query->where(function ($builder) {
                    $builder->whereNull('folder')->orWhere('folder', '');
                });
            } else {
                $query->where('folder', $currentFolder);
            }
        } elseif ($search === '' && $tag === '') {
            $query->where(function ($builder) {
                $builder->whereNull('folder')->orWhere('folder', '');
            });
        }

        $assets = $query->get()->map->toLibraryArray();

        $folders = collect($folderStats)->pluck('name')->values()->all();

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
            'folderStats' => $folderStats,
            'tags' => $tags,
            'filters' => [
                'q' => $search,
                'folder' => $currentFolder,
                'tag' => $tag,
            ],
            'disk' => config('media.disk'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $maxKb = (int) config('media.max_kb', 2048);
        $mimes = implode(',', config('media.allowed_mimes', ['jpg', 'jpeg', 'png', 'gif', 'webp']));

        $request->validate([
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['file', 'max:'.$maxKb, 'mimes:'.$mimes],
            'folder' => ['nullable', 'string', 'max:120'],
            'tags' => ['nullable', 'string', 'max:500'],
        ], [
            'files.*.max' => 'Each image must be 2 MB or smaller.',
            'files.*.mimes' => 'Only images are allowed: JPG, PNG, WebP, GIF.',
        ]);

        $uploaded = $request->file('files', []);
        if ($uploaded === []) {
            // Some clients send files as files[0], files[1] only — already covered.
            // Also accept a single file under "file".
            $single = $request->file('file');
            if ($single) {
                $uploaded = [$single];
            }
        }

        $disk = config('media.disk', 'public');
        $tags = $this->parseTags($request->input('tags'));

        foreach ($uploaded as $file) {
            if (! $file) {
                continue;
            }
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
            'original_name' => ['nullable', 'string', 'max:180'],
        ]);

        $updates = [
            'folder' => $data['folder'] ?: null,
            'tags' => $this->parseTags($data['tags'] ?? ''),
        ];

        if (array_key_exists('original_name', $data) && filled($data['original_name'])) {
            $updates['original_name'] = $this->sanitizeFileName(
                $data['original_name'],
                (string) $media->original_name,
            );
        }

        $media->update($updates);

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

    private function sanitizeFileName(string $name, string $fallback): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], '', $name));
        $name = basename($name);
        if ($name === '' || $name === '.' || $name === '..') {
            return $fallback;
        }

        $oldExt = pathinfo($fallback, PATHINFO_EXTENSION);
        $newExt = pathinfo($name, PATHINFO_EXTENSION);
        if ($oldExt !== '' && $newExt === '') {
            $name .= '.'.$oldExt;
        }

        return mb_substr($name, 0, 180);
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
