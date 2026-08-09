<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\CrmLead;
use App\Models\Funnel;
use App\Models\FunnelLead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class FunnelController extends Controller
{
    use ResolvesWorkspace;

    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request);

        return Inertia::render('Funnels/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'funnels' => Funnel::query()
                ->where('workspace_id', $workspace->id)
                ->latest()
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'headline' => ['nullable', 'string', 'max:160'],
            'subheadline' => ['nullable', 'string', 'max:400'],
            'cta_label' => ['nullable', 'string', 'max:60'],
            'cta_url' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string', 'max:8000'],
            'primary_color' => ['nullable', 'string', 'max:20'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        Funnel::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $data['name'],
            'headline' => $data['headline'] ?? $data['name'],
            'subheadline' => $data['subheadline'] ?? null,
            'cta_label' => $data['cta_label'] ?? 'Get started',
            'cta_url' => $data['cta_url'] ?? '#lead',
            'body_html' => $data['body_html'] ?? null,
            'primary_color' => $data['primary_color'] ?? ($workspace->resolveBrandKit()?->primary_color ?: '#0F766E'),
            'status' => $data['status'] ?? 'draft',
        ]);

        return back()->with('success', 'Funnel created');
    }

    public function update(Request $request, Funnel $funnel): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($funnel->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'headline' => ['nullable', 'string', 'max:160'],
            'subheadline' => ['nullable', 'string', 'max:400'],
            'cta_label' => ['nullable', 'string', 'max:60'],
            'cta_url' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string', 'max:8000'],
            'primary_color' => ['nullable', 'string', 'max:20'],
            'status' => ['sometimes', 'in:draft,published'],
        ]);

        $funnel->update($data);

        return back()->with('success', 'Funnel updated');
    }

    public function destroy(Request $request, Funnel $funnel): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($funnel->workspace_id === $workspace->id, 404);

        $funnel->delete();

        return back()->with('success', 'Funnel deleted');
    }

    public function showPublic(string $slug): Response
    {
        $funnel = Funnel::query()->where('slug', $slug)->where('status', 'published')->firstOrFail();
        $funnel->increment('views');

        return Inertia::render('Funnels/Public', [
            'funnel' => $funnel,
        ]);
    }

    public function captureLead(Request $request, string $slug): RedirectResponse
    {
        $funnel = Funnel::query()->where('slug', $slug)->where('status', 'published')->firstOrFail();

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160'],
            'phone' => ['nullable', 'string', 'max:32'],
        ]);

        FunnelLead::query()->create([
            'funnel_id' => $funnel->id,
            'workspace_id' => $funnel->workspace_id,
            'name' => $data['name'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
        ]);

        CrmLead::query()->create([
            'workspace_id' => $funnel->workspace_id,
            'name' => $data['name'] ?: Str::before($data['email'], '@'),
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'stage' => 'new',
            'source' => 'funnel:'.$funnel->slug,
        ]);

        $funnel->increment('leads');

        return back()->with('success', 'Thanks — we will reach out soon.');
    }
}
