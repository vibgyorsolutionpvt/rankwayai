<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\Workspace;
use App\Services\Workspaces\VisibleWorkspaceService;
use App\Support\BusinessTypes;
use App\Support\PublicId;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class BusinessProfileController extends Controller
{
    use ResolvesWorkspace;

    public function edit(Request $request): Response
    {
        $workspace = $this->workspace($request);

        $workspaces = app(VisibleWorkspaceService::class)
            ->forUser($request->user())
            ->map(function (Workspace $item) {
                $links = $item->social_links ?? [];
                $brand = $item->resolveBrandKit();

                return [
                    'id' => $item->id,
                    'name' => $item->name,
                    'slug' => $item->slug,
                    'role' => $item->pivot->role,
                    'business_type' => $item->business_type
                        ?? BusinessTypes::inferFromIndustry($item->industry),
                    'industry' => $item->resolvedIndustry(),
                    'tagline' => $item->tagline,
                    'description' => $item->description,
                    'city' => $item->resolvedCity(),
                    'state' => $item->state,
                    'country' => $item->country,
                    'postal_code' => $item->postal_code,
                    'address' => $item->address,
                    'phone' => $item->resolvedPhone(),
                    'whatsapp' => $item->whatsapp ?: $item->resolvedPhone(),
                    'email' => $item->resolvedEmail(),
                    'website' => $item->resolvedWebsite(),
                    'services_text' => implode("\n", $item->services ?? []),
                    'products_text' => implode("\n", $item->products ?? []),
                    'target_audience' => $item->target_audience,
                    'working_hours' => $item->working_hours,
                    'social_links' => [
                        'facebook' => $links['facebook'] ?? '',
                        'instagram' => $links['instagram'] ?? '',
                        'linkedin' => $links['linkedin'] ?? '',
                        'youtube' => $links['youtube'] ?? '',
                        'x' => $links['x'] ?? '',
                        'threads' => $links['threads'] ?? '',
                    ],
                    'logo_url' => $brand?->logo_path
                        ? Storage::disk('public')->url($brand->logo_path)
                        : null,
                ];
            });

        $activeId = PublicId::normalize($request->session()->get('active_workspace_id'));
        $active = $workspaces->firstWhere('id', $activeId) ?? $workspaces->first();

        if ($active) {
            $request->session()->put('active_workspace_id', $active['id']);
        }

        return Inertia::render('Business/Profile', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'activeWorkspace' => $active,
            'businessTypes' => BusinessTypes::options(),
        ]);
    }

    public function storeLogo(Request $request): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'logo' => ['required', 'image', 'max:4096'],
        ]);

        $brand = $workspace->resolveBrandKit();
        if (! $brand) {
            $brand = $workspace->brandKits()->create([
                'name' => 'Default',
                'is_active' => true,
            ]);
        }

        $path = $data['logo']->store('brand-kits/'.$workspace->id, 'public');
        if (! is_string($path)) {
            return back()->with('error', 'Could not save the logo. Please try again.');
        }

        $previousLogo = $brand->logo_path;
        $brand->update(['logo_path' => $path]);
        if ($previousLogo) {
            Storage::disk('public')->delete($previousLogo);
        }

        return back()->with('success', 'Business logo saved and ready for generated posters.');
    }
}
