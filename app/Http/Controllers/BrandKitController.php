<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\BrandKit;
use App\Support\BrandTones;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BrandKitController extends Controller
{
    use ResolvesWorkspace;

    public function edit(Request $request): Response
    {
        $workspace = $this->workspace($request);

        if ($workspace->brandKits()->doesntExist()) {
            $kit = BrandKit::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'Default',
                'is_active' => true,
                'primary_color' => '#0E9F90',
                'secondary_color' => '#0B1220',
                'accent_color' => '#F59E0B',
                'font_family' => 'Plus Jakarta Sans',
                'heading_font' => 'Outfit',
                'brand_tone' => 'professional',
                'default_cta_label' => 'Get started',
            ]);
        } else {
            $kitId = $request->integer('kit') ?: null;
            $kit = $kitId
                ? $workspace->brandKits()->whereKey($kitId)->first()
                : $workspace->resolveBrandKit();
            $kit ??= $workspace->brandKits()->orderBy('id')->first();
        }

        $kits = $workspace->brandKits()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (BrandKit $item) => $this->present($item));

        return Inertia::render('Brand/Edit', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'kits' => $kits,
            'brand' => $this->present($kit),
            'brandTones' => BrandTones::options(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'make_active' => ['boolean'],
        ]);

        $kit = BrandKit::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $data['name'],
            'is_active' => false,
            'primary_color' => '#0E9F90',
            'secondary_color' => '#0B1220',
            'accent_color' => '#F59E0B',
            'font_family' => 'Plus Jakarta Sans',
            'heading_font' => 'Outfit',
            'brand_tone' => 'professional',
            'default_cta_label' => 'Get started',
        ]);

        if ($request->boolean('make_active') || $workspace->brandKits()->count() === 1) {
            $kit->activate();
        }

        return redirect()
            ->route('brand.edit', ['kit' => $kit->id])
            ->with('success', 'Brand kit created');
    }

    public function update(Request $request, BrandKit $brand): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($brand->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'accent_color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'font_family' => ['required', 'string', 'max:120'],
            'heading_font' => ['nullable', 'string', 'max:120'],
            'brand_tone' => ['nullable', 'string', Rule::in(BrandTones::keys())],
            'website_url' => ['nullable', 'url', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'default_cta_label' => ['nullable', 'string', 'max:80'],
            'default_cta_url' => ['nullable', 'url', 'max:255'],
            'default_header' => ['nullable', 'string', 'max:2000'],
            'default_footer' => ['nullable', 'string', 'max:2000'],
            'social_links.facebook' => ['nullable', 'url', 'max:255'],
            'social_links.instagram' => ['nullable', 'url', 'max:255'],
            'social_links.linkedin' => ['nullable', 'url', 'max:255'],
            'social_links.x' => ['nullable', 'url', 'max:255'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'secondary_logo' => ['nullable', 'image', 'max:4096'],
            'favicon' => ['nullable', 'image', 'max:1024'],
        ]);

        $disk = 'public';
        $dir = 'brand-kits/'.$workspace->id;

        if ($request->hasFile('logo')) {
            if ($brand->logo_path) {
                Storage::disk($disk)->delete($brand->logo_path);
            }
            $brand->logo_path = $request->file('logo')->store($dir, $disk);
        }

        if ($request->hasFile('secondary_logo')) {
            if ($brand->secondary_logo_path) {
                Storage::disk($disk)->delete($brand->secondary_logo_path);
            }
            $brand->secondary_logo_path = $request->file('secondary_logo')->store($dir.'/secondary', $disk);
        }

        if ($request->hasFile('favicon')) {
            if ($brand->favicon_path) {
                Storage::disk($disk)->delete($brand->favicon_path);
            }
            $brand->favicon_path = $request->file('favicon')->store($dir.'/favicon', $disk);
        }

        $brand->fill([
            'name' => $data['name'],
            'primary_color' => $data['primary_color'],
            'secondary_color' => $data['secondary_color'],
            'accent_color' => $data['accent_color'] ?? null,
            'font_family' => $data['font_family'],
            'heading_font' => trim((string) ($data['heading_font'] ?? '')) ?: null,
            'brand_tone' => $data['brand_tone'] ?? null,
            'website_url' => $data['website_url'] ?? null,
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'default_cta_label' => $data['default_cta_label'] ?? null,
            'default_cta_url' => $data['default_cta_url'] ?? null,
            'default_header' => trim((string) ($data['default_header'] ?? '')) ?: null,
            'default_footer' => trim((string) ($data['default_footer'] ?? '')) ?: null,
            'social_links' => $data['social_links'] ?? [],
        ])->save();

        return back()->with('success', 'Brand kit saved');
    }

    public function activate(Request $request, BrandKit $brand): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($brand->workspace_id === $workspace->id, 404);

        $brand->activate();

        return redirect()
            ->route('brand.edit', ['kit' => $brand->id])
            ->with('success', 'Active brand kit updated — SMM / Channels / AI will use this one.');
    }

    public function destroy(Request $request, BrandKit $brand): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($brand->workspace_id === $workspace->id, 404);

        if ($workspace->brandKits()->count() <= 1) {
            return back()->with('error', 'At least one brand kit is required.');
        }

        $wasActive = $brand->is_active;
        foreach (['logo_path', 'secondary_logo_path', 'favicon_path'] as $col) {
            if ($brand->{$col}) {
                Storage::disk('public')->delete($brand->{$col});
            }
        }
        $brand->delete();

        if ($wasActive) {
            $next = $workspace->brandKits()->orderBy('id')->first();
            $next?->activate();
        }

        return redirect()
            ->route('brand.edit')
            ->with('success', 'Brand kit deleted');
    }

    public function destroyLogo(Request $request, BrandKit $brand): RedirectResponse
    {
        return $this->destroyAsset($request, $brand, 'logo_path', 'Logo removed');
    }

    public function destroySecondaryLogo(Request $request, BrandKit $brand): RedirectResponse
    {
        return $this->destroyAsset($request, $brand, 'secondary_logo_path', 'Secondary logo removed');
    }

    public function destroyFavicon(Request $request, BrandKit $brand): RedirectResponse
    {
        return $this->destroyAsset($request, $brand, 'favicon_path', 'Favicon removed');
    }

    private function destroyAsset(Request $request, BrandKit $brand, string $column, string $message): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($brand->workspace_id === $workspace->id, 404);

        if ($brand->{$column}) {
            Storage::disk('public')->delete($brand->{$column});
            $brand->update([$column => null]);
        }

        return back()->with('success', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BrandKit $brand): array
    {
        $disk = Storage::disk('public');

        return [
            ...$brand->toArray(),
            'logo_url' => $brand->logo_path ? $disk->url($brand->logo_path) : null,
            'secondary_logo_url' => $brand->secondary_logo_path ? $disk->url($brand->secondary_logo_path) : null,
            'favicon_url' => $brand->favicon_path ? $disk->url($brand->favicon_path) : null,
            'style_tokens' => $brand->styleTokens(),
        ];
    }
}
