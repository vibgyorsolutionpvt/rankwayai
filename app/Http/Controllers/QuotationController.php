<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ResolvesWorkspace;
use App\Models\CrmLead;
use App\Models\Itinerary;
use App\Models\Quotation;
use App\Services\Studio\QuotationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class QuotationController extends Controller
{
    use ResolvesWorkspace;

    public function index(Request $request, QuotationService $quotations): Response
    {
        $workspace = $this->workspace($request);

        return Inertia::render('Studio/Quotations/Index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'quotations' => $workspace->quotations()
                ->latest('id')
                ->get()
                ->map(fn (Quotation $item) => [
                    'id' => $item->id,
                    'number' => $item->number,
                    'title' => $item->title,
                    'status' => $item->status,
                    'customer_name' => $item->customer_name,
                    'destination' => $item->destination,
                    'total' => (float) $item->total,
                    'currency' => $item->currency,
                    'share_url' => $item->publicUrl(),
                    'updated_at' => $item->updated_at?->timezone(config('app.timezone'))->format('d M Y, g:i A'),
                ]),
            'itineraries' => $workspace->itineraries()
                ->latest('id')
                ->limit(50)
                ->get(['id', 'title', 'destination', 'duration_days', 'adults', 'children'])
                ->map(fn (Itinerary $i) => [
                    'id' => $i->id,
                    'title' => $i->title,
                    'destination' => $i->destination,
                    'duration_days' => $i->duration_days,
                    'travellers' => $i->travellersCount(),
                ]),
            'leads' => CrmLead::query()
                ->where('workspace_id', $workspace->id)
                ->latest('id')
                ->limit(50)
                ->get(['id', 'name', 'company', 'email', 'phone'])
                ->map(fn (CrmLead $lead) => [
                    'id' => $lead->id,
                    'name' => $lead->name,
                    'company' => $lead->company,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                ]),
            'defaults' => [
                'currency' => 'INR',
                'travellers' => 2,
                'tax_percent' => 5,
                'valid_until' => now()->addDays(14)->toDateString(),
                'payment_terms' => '50% advance to confirm. Balance before travel.',
            ],
        ]);
    }

    public function store(Request $request, QuotationService $quotations): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'itinerary_id' => [
                'nullable',
                'uuid',
                Rule::exists('itineraries', 'id')->where(fn ($q) => $q->where('workspace_id', $workspace->id)),
            ],
            'crm_lead_id' => [
                'nullable',
                'integer',
                Rule::exists('crm_leads', 'id')->where(fn ($q) => $q->where('workspace_id', $workspace->id)),
            ],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_email' => ['nullable', 'email', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'customer_company' => ['nullable', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'size:3'],
            'travellers' => ['nullable', 'integer', 'min:1', 'max:100'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'string', Rule::in(['draft', 'sent', 'accepted', 'declined'])],
        ]);

        $itinerary = null;
        if (! empty($data['itinerary_id'])) {
            $itinerary = Itinerary::query()
                ->where('workspace_id', $workspace->id)
                ->findOrFail($data['itinerary_id']);
        }

        if (! empty($data['crm_lead_id'])) {
            $lead = CrmLead::query()
                ->where('workspace_id', $workspace->id)
                ->findOrFail($data['crm_lead_id']);
            $data['customer_name'] = $data['customer_name'] ?? $lead->name;
            $data['customer_email'] = $data['customer_email'] ?? $lead->email;
            $data['customer_phone'] = $data['customer_phone'] ?? $lead->phone;
            $data['customer_company'] = $data['customer_company'] ?? $lead->company;
        }

        $quotation = $quotations->create($workspace, $request->user()->id, $data, $itinerary);

        return redirect()
            ->route('studio.quotations.edit', $quotation)
            ->with('success', $itinerary ? 'Quotation generated from itinerary.' : 'Quotation created.');
    }

    public function fromItinerary(Request $request, Itinerary $itinerary, QuotationService $quotations): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($itinerary->workspace_id === $workspace->id, 404);

        $quotation = $quotations->create($workspace, $request->user()->id, [
            'status' => 'draft',
            'currency' => $itinerary->currency ?: 'INR',
            'discount_amount' => (float) (($itinerary->pricing['discount'] ?? 0)),
            'tax_percent' => 0,
        ], $itinerary);

        return redirect()
            ->route('studio.quotations.edit', $quotation)
            ->with('success', 'Quotation generated from itinerary.');
    }

    public function edit(Request $request, Quotation $quotation, QuotationService $quotations): Response
    {
        $workspace = $this->workspace($request);
        abort_unless($quotation->workspace_id === $workspace->id, 404);

        return Inertia::render('Studio/Quotations/Edit', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'quotation' => $quotations->present($quotation),
            'leads' => CrmLead::query()
                ->where('workspace_id', $workspace->id)
                ->latest('id')
                ->limit(50)
                ->get(['id', 'name', 'company', 'email', 'phone'])
                ->map(fn (CrmLead $lead) => [
                    'id' => $lead->id,
                    'name' => $lead->name,
                    'company' => $lead->company,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                ]),
        ]);
    }

    public function update(Request $request, Quotation $quotation, QuotationService $quotations): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($quotation->workspace_id === $workspace->id, 404);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'status' => ['required', 'string', Rule::in(['draft', 'sent', 'accepted', 'declined'])],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'customer_email' => ['nullable', 'email', 'max:160'],
            'customer_phone' => ['nullable', 'string', 'max:40'],
            'customer_company' => ['nullable', 'string', 'max:120'],
            'trip_title' => ['nullable', 'string', 'max:160'],
            'destination' => ['nullable', 'string', 'max:120'],
            'travellers' => ['required', 'integer', 'min:1', 'max:100'],
            'duration_days' => ['nullable', 'integer', 'min:1', 'max:60'],
            'currency' => ['nullable', 'string', 'size:3'],
            'line_items' => ['nullable', 'array'],
            'line_items.*.description' => ['nullable', 'string', 'max:240'],
            'line_items.*.qty' => ['nullable', 'numeric', 'min:0.01'],
            'line_items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'discount_amount' => ['nullable', 'numeric', 'min:0'],
            'tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'payment_terms' => ['nullable', 'string', 'max:2000'],
            'valid_until' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'inclusions_text' => ['nullable', 'string', 'max:5000'],
            'exclusions_text' => ['nullable', 'string', 'max:5000'],
            'is_public' => ['sometimes', 'boolean'],
            'crm_lead_id' => [
                'nullable',
                'integer',
                Rule::exists('crm_leads', 'id')->where(fn ($q) => $q->where('workspace_id', $workspace->id)),
            ],
        ]);

        $quotations->applyEditorPayload($quotation, $data);

        return back()->with('success', 'Quotation saved.');
    }

    public function destroy(Request $request, Quotation $quotation): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($quotation->workspace_id === $workspace->id, 404);

        $quotation->delete();

        return redirect()
            ->route('studio.quotations.index')
            ->with('success', 'Quotation deleted.');
    }

    public function duplicate(Request $request, Quotation $quotation): RedirectResponse
    {
        $workspace = $this->workspace($request);
        $this->authorize('update', $workspace);
        abort_unless($quotation->workspace_id === $workspace->id, 404);

        $copy = $quotation->replicate(['share_token', 'views_count', 'downloads_count', 'number']);
        $copy->title = $quotation->title.' (copy)';
        $copy->status = 'draft';
        $copy->created_by = $request->user()->id;
        $copy->share_token = null;
        $copy->views_count = 0;
        $copy->downloads_count = 0;
        $copy->number = app(QuotationService::class)->nextNumber($workspace);
        $copy->save();

        return redirect()
            ->route('studio.quotations.edit', $copy)
            ->with('success', 'Quotation duplicated.');
    }

    public function pdf(Request $request, Quotation $quotation, QuotationService $quotations): SymfonyResponse
    {
        $workspace = $this->workspace($request);
        abort_unless($quotation->workspace_id === $workspace->id, 404);

        $quotations->track($quotation, 'download');

        return $quotations->pdf($quotation->load('workspace'));
    }
}
