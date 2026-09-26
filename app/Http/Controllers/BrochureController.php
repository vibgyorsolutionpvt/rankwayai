<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\Brochure;
use App\Services\Studio\BrochureService;
use App\Support\BrochureTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BrochureController extends Controller
{
    use ResolvesWorkspace;

    public function index(Request $request, BrochureService $brochures): Response
    {
        $workspace = $this->workspace($request);

        return Inertia::render('Studio/Brochures/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'brochures' => $workspace->brochures()
                ->latest('id')
                ->get()
                ->map(fn (Brochure $item) => $brochures->present($item)),
            'templates' => BrochureTemplates::options(),
            'defaults' => [
                'headline' => $workspace->name,
                'subheadline' => $workspace->businessProfile()['tagline'] ?? null,
                'brand_kit_id' => $workspace->resolveBrandKit()?->id,
            ],
            'brandKits' => $workspace->brandKits()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'is_active'])
                ->map(fn ($kit) => [
                    'id' => $kit->id,
                    'name' => $kit->name,
                    'is_active' => (bool) $kit->is_active,
                ]),
        ]);
    }

    public function store(Request $request, BrochureService $brochures): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $this->validated($request, $workspace->id, creating: true);
        $profile = $workspace->businessProfile();

        $brochure = Brochure::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'brand_kit_id' => $data['brand_kit_id'] ?? $workspace->resolveBrandKit()?->id,
            'title' => $data['title'],
            'template_key' => $data['template_key'],
            'status' => $data['status'],
            'headline' => $data['headline'] ?? $workspace->name,
            'subheadline' => $data['subheadline'] ?? ($profile['tagline'] ?? null),
            'sections' => BrochureTemplates::defaultSections($profile, $data['template_key']),
            'is_public' => $data['is_public'] ?? true,
        ]);

        return redirect()
            ->route('studio.brochures.edit', $brochure)
            ->with('success', 'Brochure created — edit sections next.');
    }

    public function edit(Request $request, Brochure $brochure, BrochureService $brochures): Response
    {
        $workspace = $this->workspace($request);
        abort_unless($brochure->workspace_id === $workspace->id, 404);

        return Inertia::render('Studio/Brochures/Edit', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'brochure' => $brochures->present($brochure),
            'templates' => BrochureTemplates::options(),
            'brandKits' => $workspace->brandKits()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(['id', 'name', 'is_active'])
                ->map(fn ($kit) => [
                    'id' => $kit->id,
                    'name' => $kit->name,
                    'is_active' => (bool) $kit->is_active,
                ]),
        ]);
    }

    public function update(Request $request, Brochure $brochure, BrochureService $brochures): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($brochure->workspace_id === $workspace->id, 404);

        $data = $this->validated($request, $workspace->id, creating: false);

        $brochure->fill([
            'brand_kit_id' => $data['brand_kit_id'] ?? null,
            'title' => $data['title'],
            'template_key' => $data['template_key'],
            'status' => $data['status'],
            'headline' => $data['headline'] ?? null,
            'subheadline' => $data['subheadline'] ?? null,
            'sections' => $brochures->normalizeSections($data['sections'] ?? []),
            'is_public' => $data['is_public'] ?? true,
        ])->save();

        return back()->with('success', 'Brochure saved.');
    }

    public function destroy(Request $request, Brochure $brochure): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($brochure->workspace_id === $workspace->id, 404);

        $brochure->delete();

        return redirect()
            ->route('studio.brochures.index')
            ->with('success', 'Brochure deleted.');
    }

    public function duplicate(Request $request, Brochure $brochure): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($brochure->workspace_id === $workspace->id, 404);

        $copy = $brochure->replicate(['share_token', 'views_count', 'downloads_count', 'cta_clicks']);
        $copy->title = $brochure->title.' (copy)';
        $copy->status = 'draft';
        $copy->created_by = $request->user()->id;
        $copy->share_token = null;
        $copy->views_count = 0;
        $copy->downloads_count = 0;
        $copy->cta_clicks = 0;
        $copy->save();

        return redirect()
            ->route('studio.brochures.edit', $copy)
            ->with('success', 'Brochure duplicated.');
    }

    public function pdf(Request $request, Brochure $brochure, BrochureService $brochures): SymfonyResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($brochure->workspace_id === $workspace->id, 404);

        $brochures->track($brochure, 'download', $request);

        return $brochures->pdf($brochure);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $workspaceId, bool $creating): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:160'],
            'template_key' => ['required', 'string', Rule::in(BrochureTemplates::keys())],
            'status' => ['required', 'string', Rule::in(['draft', 'published'])],
            'headline' => ['nullable', 'string', 'max:200'],
            'subheadline' => ['nullable', 'string', 'max:300'],
            'brand_kit_id' => [
                'nullable',
                'integer',
                Rule::exists('brand_kits', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)),
            ],
            'is_public' => ['sometimes', 'boolean'],
        ];

        if (! $creating) {
            $rules['sections'] = ['nullable', 'array'];
            $rules['sections.*.key'] = ['nullable', 'string', 'max:40'];
            $rules['sections.*.title'] = ['nullable', 'string', 'max:120'];
            $rules['sections.*.body'] = ['nullable', 'string', 'max:5000'];
            $rules['sections.*.items'] = ['nullable'];
            $rules['sections.*.enabled'] = ['nullable', 'boolean'];
        }

        return $request->validate($rules);
    }
}
