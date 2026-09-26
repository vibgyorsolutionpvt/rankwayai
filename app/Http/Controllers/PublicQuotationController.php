<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use App\Services\Studio\QuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PublicQuotationController extends Controller
{
    public function show(string $token, Request $request, QuotationService $quotations): Response
    {
        $quotation = Quotation::query()
            ->with(['workspace', 'brandKit'])
            ->where('share_token', $token)
            ->firstOrFail();

        abort_unless($quotation->isPublished(), 404);

        $quotations->track($quotation, 'view');

        return Inertia::render('Studio/Quotations/Public', [
            'quotation' => $quotations->present($quotation->fresh(), false),
            'pdf_url' => route('studio.quotations.public.pdf', $token),
            'track_url' => route('studio.quotations.public.track', $token),
        ]);
    }

    public function track(string $token, Request $request, QuotationService $quotations): JsonResponse
    {
        $quotation = Quotation::query()
            ->where('share_token', $token)
            ->firstOrFail();

        abort_unless($quotation->isPublished(), 404);

        $quotations->track($quotation, (string) $request->input('event', 'view'));

        return response()->json(['ok' => true]);
    }

    public function pdf(string $token, QuotationService $quotations): SymfonyResponse
    {
        $quotation = Quotation::query()
            ->with(['workspace', 'brandKit'])
            ->where('share_token', $token)
            ->firstOrFail();

        abort_unless($quotation->isPublished(), 404);

        $quotations->track($quotation, 'download');

        return $quotations->pdf($quotation);
    }
}
