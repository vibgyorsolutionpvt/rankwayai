<?php

namespace App\Http\Controllers;

use App\Models\Brochure;
use App\Services\Studio\BrochureService;
use App\Services\Studio\QrCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PublicBrochureController extends Controller
{
    public function show(string $token, Request $request, BrochureService $brochures): Response
    {
        $brochure = Brochure::query()
            ->with(['workspace', 'brandKit'])
            ->where('share_token', $token)
            ->firstOrFail();

        abort_unless($brochure->isPublished(), 404);

        $brochures->track($brochure, 'view', $request);

        return Inertia::render('Studio/Brochures/Public', [
            'brochure' => $brochures->present($brochure->fresh(), false),
            'track_url' => route('studio.brochures.public.track', $token),
            'pdf_url' => route('studio.brochures.public.pdf', $token),
        ]);
    }

    public function qr(string $token, QrCodeService $qr): SymfonyResponse
    {
        $brochure = Brochure::query()
            ->where('share_token', $token)
            ->firstOrFail();

        return $qr->response(
            $brochure->publicUrl(),
            ($brochure->title ?: 'brochure').'-qr',
            false,
        );
    }

    public function track(string $token, Request $request, BrochureService $brochures): JsonResponse
    {
        $brochure = Brochure::query()
            ->where('share_token', $token)
            ->firstOrFail();

        abort_unless($brochure->isPublished(), 404);

        $brochures->track($brochure, (string) $request->input('event', ''), $request);

        return response()->json(['ok' => true]);
    }

    public function pdf(string $token, Request $request, BrochureService $brochures): SymfonyResponse
    {
        $brochure = Brochure::query()
            ->with(['workspace', 'brandKit'])
            ->where('share_token', $token)
            ->firstOrFail();

        abort_unless($brochure->isPublished(), 404);

        $brochures->track($brochure, 'download', $request);

        return $brochures->pdf($brochure);
    }
}
