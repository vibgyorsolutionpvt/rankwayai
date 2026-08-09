<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\Integrations\WorkspaceIntegrationService;
use App\Services\WhatsApp\MetaWhatsAppCloudService;
use App\Services\WhatsApp\WhatsAppConversationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class MetaWhatsAppWebhookController extends Controller
{
    public function verify(
        Request $request,
        Workspace $workspace,
        WorkspaceIntegrationService $integrations
    ): SymfonyResponse {
        $cfg = $integrations->whatsappMetaConfig($workspace);
        if (! $cfg) {
            return response('WhatsApp Meta not configured', 404);
        }

        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        if ($mode === 'subscribe' && hash_equals($cfg['verify_token'], $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(
        Request $request,
        Workspace $workspace,
        MetaWhatsAppCloudService $meta,
        WhatsAppConversationService $conversations
    ): Response {
        $signature = $request->header('X-Hub-Signature-256');
        if (! $meta->verifySignature($workspace, $request->getContent(), $signature)) {
            return response('Invalid signature', 401);
        }

        $payload = $request->all();
        if (($payload['object'] ?? '') === 'whatsapp_business_account') {
            $conversations->ingestMetaWebhook($workspace, $payload);
        }

        return response('EVENT_RECEIVED', 200);
    }
}
