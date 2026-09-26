<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\BusinessCard;
use App\Services\Studio\BusinessCardService;
use App\Support\BusinessCardTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BusinessCardController extends Controller
{
    use ResolvesWorkspace;

    public function index(Request $request, BusinessCardService $cards): Response
    {
        $workspace = $this->workspace($request);
        $profile = $workspace->businessProfile();

        $list = $workspace->businessCards()
            ->latest('created_at')
            ->get()
            ->map(fn (BusinessCard $card) => $cards->present($card));

        return Inertia::render('Studio/Cards/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'cards' => $list,
            'templates' => BusinessCardTemplates::options(),
            'defaults' => [
                'company_name' => $workspace->name,
                'tagline' => $profile['tagline'] ?? null,
                'phone' => $profile['phone'] ?? null,
                'whatsapp' => $profile['whatsapp'] ?? null,
                'email' => $profile['email'] ?? null,
                'website' => $profile['website'] ?? null,
                'address' => $profile['address'] ?? null,
                'brand_kit_id' => $workspace->resolveBrandKit()?->id,
            ],
            'brandKits' => $workspace->brandKits()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(function ($kit) {
                    $disk = Storage::disk(config('filesystems.public_disk', 'public'));

                    return [
                        'id' => $kit->id,
                        'name' => $kit->name,
                        'is_active' => (bool) $kit->is_active,
                        'primary_color' => $kit->primary_color,
                        'secondary_color' => $kit->secondary_color,
                        'accent_color' => $kit->accent_color,
                        'heading_font' => $kit->heading_font,
                        'font_family' => $kit->font_family,
                        'logo_url' => $kit->logo_path ? $disk->url($kit->logo_path) : null,
                    ];
                }),
        ]);
    }

    public function store(Request $request, BusinessCardService $cards): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $this->validated($request, $workspace->id);
        $profile = $workspace->businessProfile();

        $card = BusinessCard::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $request->user()->id,
            'brand_kit_id' => $data['brand_kit_id'] ?? $workspace->resolveBrandKit()?->id,
            'title' => $data['title'],
            'template_key' => $data['template_key'],
            'status' => $data['status'],
            'person_name' => $data['person_name'] ?? null,
            'person_title' => $data['person_title'] ?? null,
            'pronouns' => $data['pronouns'] ?? null,
            'company_name' => $data['company_name'] ?? $workspace->name,
            'tagline' => $data['tagline'] ?? ($profile['tagline'] ?? null),
            'phone' => $data['phone'] ?? ($profile['phone'] ?? null),
            'whatsapp' => $data['whatsapp'] ?? ($profile['whatsapp'] ?? null),
            'email' => $data['email'] ?? ($profile['email'] ?? null),
            'website' => $data['website'] ?? ($profile['website'] ?? null),
            'address' => $data['address'] ?? ($profile['address'] ?? null),
            'social_links' => $data['social_links'] ?? [],
            'is_public' => $data['is_public'] ?? true,
        ]);

        if ($request->hasFile('person_photo')) {
            $card->person_photo_path = $request->file('person_photo')->store(
                'business-cards/'.$workspace->id,
                'public'
            );
            $card->save();
        }

        if ($request->hasFile('cover_image')) {
            $card->cover_image_path = $request->file('cover_image')->store(
                'business-cards/'.$workspace->id.'/covers',
                'public'
            );
            $card->save();
        }

        if ($request->hasFile('logo')) {
            $card->logo_path = $request->file('logo')->store(
                'business-cards/'.$workspace->id.'/logos',
                'public'
            );
            $card->save();
        }

        return redirect()
            ->route('studio.cards.edit', $card)
            ->with('success', 'Business card created.');
    }

    public function edit(Request $request, BusinessCard $card, BusinessCardService $cards): Response
    {
        $workspace = $this->workspace($request);
        abort_unless((int) $card->workspace_id === (int) $workspace->id, 404);

        return Inertia::render('Studio/Cards/Edit', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'card' => $cards->present($card),
            'templates' => BusinessCardTemplates::options(),
            'brandKits' => $workspace->brandKits()
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get()
                ->map(function ($kit) {
                    $disk = Storage::disk(config('filesystems.public_disk', 'public'));

                    return [
                        'id' => $kit->id,
                        'name' => $kit->name,
                        'is_active' => (bool) $kit->is_active,
                        'primary_color' => $kit->primary_color,
                        'secondary_color' => $kit->secondary_color,
                        'accent_color' => $kit->accent_color,
                        'heading_font' => $kit->heading_font,
                        'font_family' => $kit->font_family,
                        'logo_url' => $kit->logo_path ? $disk->url($kit->logo_path) : null,
                    ];
                }),
        ]);
    }

    public function update(Request $request, BusinessCard $card): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless((int) $card->workspace_id === (int) $workspace->id, 404);

        $data = $this->validated($request, $workspace->id);

        if ($request->hasFile('person_photo')) {
            if ($card->person_photo_path) {
                Storage::disk('public')->delete($card->person_photo_path);
            }
            $card->person_photo_path = $request->file('person_photo')->store(
                'business-cards/'.$workspace->id,
                'public'
            );
        }

        if ($request->hasFile('cover_image')) {
            if ($card->cover_image_path) {
                Storage::disk('public')->delete($card->cover_image_path);
            }
            $card->cover_image_path = $request->file('cover_image')->store(
                'business-cards/'.$workspace->id.'/covers',
                'public'
            );
        }

        if ($request->boolean('remove_logo')) {
            if ($card->logo_path) {
                Storage::disk('public')->delete($card->logo_path);
            }
            $card->logo_path = null;
        } elseif ($request->hasFile('logo')) {
            if ($card->logo_path) {
                Storage::disk('public')->delete($card->logo_path);
            }
            $card->logo_path = $request->file('logo')->store(
                'business-cards/'.$workspace->id.'/logos',
                'public'
            );
        }

        $card->fill([
            'brand_kit_id' => $data['brand_kit_id'] ?? null,
            'title' => $data['title'],
            'template_key' => $data['template_key'],
            'status' => $data['status'],
            'person_name' => $data['person_name'] ?? null,
            'person_title' => $data['person_title'] ?? null,
            'pronouns' => $data['pronouns'] ?? null,
            'company_name' => $data['company_name'] ?? null,
            'tagline' => $data['tagline'] ?? null,
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
            'email' => $data['email'] ?? null,
            'website' => $data['website'] ?? null,
            'address' => $data['address'] ?? null,
            'social_links' => $data['social_links'] ?? [],
            'is_public' => $data['is_public'] ?? true,
        ])->save();

        return back()->with('success', 'Business card saved.');
    }

    public function destroy(Request $request, BusinessCard $card): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless((int) $card->workspace_id === (int) $workspace->id, 404);

        if ($card->person_photo_path) {
            Storage::disk('public')->delete($card->person_photo_path);
        }
        if ($card->cover_image_path) {
            Storage::disk('public')->delete($card->cover_image_path);
        }
        if ($card->logo_path) {
            Storage::disk('public')->delete($card->logo_path);
        }
        $card->delete();

        return redirect()
            ->route('studio.cards.index')
            ->with('success', 'Business card deleted.');
    }

    public function duplicate(Request $request, BusinessCard $card): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless((int) $card->workspace_id === (int) $workspace->id, 404);

        $copy = $card->replicate(['share_token', 'views_count', 'phone_clicks', 'whatsapp_clicks', 'email_clicks', 'website_clicks']);
        $copy->title = $card->title.' (copy)';
        $copy->status = 'draft';
        $copy->created_by = $request->user()->id;
        $copy->share_token = null;
        $copy->views_count = 0;
        $copy->phone_clicks = 0;
        $copy->whatsapp_clicks = 0;
        $copy->email_clicks = 0;
        $copy->website_clicks = 0;
        $copy->save();

        return redirect()
            ->route('studio.cards.edit', $copy)
            ->with('success', 'Card duplicated.');
    }

    public function pdf(Request $request, BusinessCard $card, BusinessCardService $cards): SymfonyResponse
    {
        $workspace = $this->workspace($request);
        abort_unless((int) $card->workspace_id === (int) $workspace->id, 404);

        return $cards->pdf($card);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $workspaceId): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'template_key' => ['required', 'string', Rule::in(BusinessCardTemplates::keys())],
            'status' => ['required', 'string', Rule::in(['draft', 'published'])],
            'person_name' => ['nullable', 'string', 'max:120'],
            'person_title' => ['nullable', 'string', 'max:120'],
            'pronouns' => ['nullable', 'string', 'max:40'],
            'company_name' => ['nullable', 'string', 'max:120'],
            'tagline' => ['nullable', 'string', 'max:280'],
            'phone' => ['nullable', 'string', 'max:40'],
            'whatsapp' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'brand_kit_id' => [
                'nullable',
                'integer',
                Rule::exists('brand_kits', 'id')->where(fn ($q) => $q->where('workspace_id', $workspaceId)),
            ],
            'is_public' => ['sometimes', 'boolean'],
            'person_photo' => ['nullable', 'image', 'max:4096'],
            'cover_image' => ['nullable', 'image', 'max:5120'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'remove_logo' => ['sometimes', 'boolean'],
            'social_links' => ['nullable', 'array'],
            'social_links.facebook' => ['nullable', 'string', 'max:255'],
            'social_links.instagram' => ['nullable', 'string', 'max:255'],
            'social_links.linkedin' => ['nullable', 'string', 'max:255'],
            'social_links.x' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
