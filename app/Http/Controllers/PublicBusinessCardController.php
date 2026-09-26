<?php

namespace App\Http\Controllers;

use App\Models\BusinessCard;
use App\Services\Studio\BusinessCardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
