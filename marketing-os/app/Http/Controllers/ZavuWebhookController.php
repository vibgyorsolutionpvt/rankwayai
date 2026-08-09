<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\Integrations\WorkspaceIntegrationService;
use App\Services\WhatsApp\WhatsAppConversationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ZavuWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        Workspace $workspace,
        WhatsAppConversationService $conversations,
        WorkspaceIntegrationService $integrations
    ): Response {
        $secret = $integrations->credential($workspace, 'zavu', 'webhook_secret')
            ?: config('services.zavu.webhook_secret');

        if (filled($secret)) {
            $header = (string) $request->header('X-Zavu-Signature', $request->header('X-Webhook-Secret', ''));
            if (! hash_equals((string) $secret, $header)) {
                return response('Invalid signature', 401);
            }
        }

        $payload = $request->all();
        $event = (string) ($payload['event'] ?? $payload['type'] ?? 'message.inbound');

        if (str_contains($event, 'inbound') || ($payload['channel'] ?? null) === 'whatsapp') {
            $channel = $payload['channel']
                ?? $payload['data']['channel']
                ?? $payload['message']['channel']
                ?? 'whatsapp';

            if ($channel === 'whatsapp' || blank($channel)) {
                $conversations->ingestInbound($workspace, $payload);
            }
        }

        return response('ok', 200);
    }
}
