<?php

namespace App\Http\Controllers;

use App\Models\BusinessCard;
use App\Services\Studio\BusinessCardService;
use App\Services\Studio\QrCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class PublicBusinessCardController extends Controller
{
    public function show(string $token, Request $request, BusinessCardService $cards): Response
    {
        $card = BusinessCard::query()
            ->with(['workspace', 'brandKit'])
            ->where('share_token', $token)
            ->firstOrFail();

        abort_unless($card->isPublished(), 404);

        $cards->track($card, 'view', $request);

        return Inertia::render('Studio/Cards/Public', [
            'card' => $cards->present($card->fresh(), false),
            'track_url' => route('studio.cards.public.track', $token),
        ]);
    }

    public function qr(string $token, Request $request, QrCodeService $qr): SymfonyResponse
    {
        $card = BusinessCard::query()
            ->where('share_token', $token)
            ->firstOrFail();

        $download = $request->boolean('download');

        return $qr->response(
            $card->publicUrl(),
            ($card->title ?: 'business-card').'-qr',
            $download,
        );
    }

    public function track(string $token, Request $request, BusinessCardService $cards): JsonResponse
    {
        $card = BusinessCard::query()
            ->where('share_token', $token)
            ->firstOrFail();

        abort_unless($card->isPublished(), 404);

        $event = (string) $request->input('event', '');
        $cards->track($card, $event, $request);

        return response()->json(['ok' => true]);
    }
}
