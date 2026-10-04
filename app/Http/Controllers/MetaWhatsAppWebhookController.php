<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Services\Integrations\WorkspaceIntegrationService;
use App\Services\WhatsApp\MetaWhatsAppCloudService;
use App\Services\WhatsApp\WhatsAppConversationService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class MetaWhatsAppWebhookController extends Controller
{
    /**
     * App-level webhook for the RankwayAI Meta app. Every WABA onboarded through
     * Embedded Signup delivers here; the workspace is resolved by phone_number_id.
     */
    public function verifyApp(Request $request): SymfonyResponse
    {
        $expected = (string) config('services.meta.whatsapp_webhook_verify_token');
        if ($expected === '') {
            return response('Webhook verify token not configured', 404);
        }

        return $this->challenge($request, $expected);
    }

    public function receiveApp(
        Request $request,
        WorkspaceIntegrationService $integrations,
        WhatsAppConversationService $conversations,
    ): Response {
        $secret = (string) config('services.meta.app_secret');
        $raw = $request->getContent();
        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if ($secret === '' || ! hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature)) {
            Log::channel('whatsapp')->warning('whatsapp.webhook.invalid_signature', [
                'app_secret_set' => $secret !== '',
                'signature_present' => $signature !== '',
                'bytes' => strlen($raw),
            ]);

            return response('Invalid signature', 401);
        }

        $payload = $request->all();
        if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
            return response('EVENT_RECEIVED', 200);
        }

        foreach ($this->splitByPhoneNumberId($payload) as $phoneId => $subPayload) {
            $values = array_column(array_merge(...array_column($subPayload['entry'], 'changes')), 'value');
            Log::channel('whatsapp')->info('whatsapp.webhook.received', [
                'phone_number_id' => $phoneId,
                'messages' => count(array_merge(...array_map(fn ($v) => $v['messages'] ?? [], $values))),
                'statuses' => array_map(
                    fn ($s) => ($s['status'] ?? '?').(isset($s['errors'][0]['code']) ? ':'.$s['errors'][0]['code'] : ''),
                    array_merge(...array_map(fn ($v) => $v['statuses'] ?? [], $values))
                ),
            ]);

            $workspace = $integrations->workspaceForWhatsappPhoneId((string) $phoneId);
            if (! $workspace) {
                Log::channel('whatsapp')->info('whatsapp.webhook.unknown_phone', ['phone_number_id' => $phoneId]);

                continue;
            }

            $conversations->ingestMetaWebhook($workspace, $subPayload);
        }

        return response('EVENT_RECEIVED', 200);
    }

    public function verify(
        Request $request,
        Workspace $workspace,
        WorkspaceIntegrationService $integrations
    ): SymfonyResponse {
        $cfg = $integrations->whatsappMetaConfig($workspace);
        if (! $cfg) {
            return response('WhatsApp Meta not configured', 404);
        }

        return $this->challenge($request, $cfg['verify_token']);
    }

    public function receive(
        Request $request,
        Workspace $workspace,
        MetaWhatsAppCloudService $meta,
        WhatsAppConversationService $conversations,
        WorkspaceIntegrationService $integrations,
    ): Response {
        $signature = $request->header('X-Hub-Signature-256');
        if (! $meta->verifySignature($workspace, $request->getContent(), $signature)) {
            return response('Invalid signature', 401);
        }

        $payload = $request->all();
        if (($payload['object'] ?? '') === 'whatsapp_business_account') {
            $conversations->ingestMetaWebhook($workspace, $this->onlyOwnNumber($payload, $workspace, $integrations));
        }

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * A shared Meta app delivers every subscribed WABA to one URL, so never let another
     * workspace's number land in this inbox.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function onlyOwnNumber(array $payload, Workspace $workspace, WorkspaceIntegrationService $integrations): array
    {
        $ownPhoneId = (string) ($integrations->whatsappMetaConfig($workspace)['phone_number_id'] ?? '');
        $entries = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            $changes = array_values(array_filter($entry['changes'] ?? [], function ($change) use ($ownPhoneId) {
                $phoneId = (string) ($change['value']['metadata']['phone_number_id'] ?? '');

                return $phoneId === '' || $phoneId === $ownPhoneId;
            }));
            if ($changes !== []) {
                $entries[] = array_merge($entry, ['changes' => $changes]);
            }
        }

        return array_merge($payload, ['entry' => $entries]);
    }

    private function challenge(Request $request, string $expected): SymfonyResponse
    {
        $mode = (string) $request->query('hub_mode', $request->query('hub.mode', ''));
        $token = (string) $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', $request->query('hub.challenge', ''));

        if ($mode === 'subscribe' && hash_equals($expected, $token)) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, array<string, mixed>>
     */
    private function splitByPhoneNumberId(array $payload): array
    {
        $out = [];
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $phoneId = (string) ($change['value']['metadata']['phone_number_id'] ?? '');
                if ($phoneId === '') {
                    continue;
                }
                $out[$phoneId] ??= ['object' => $payload['object'], 'entry' => []];
                $out[$phoneId]['entry'][] = ['id' => $entry['id'] ?? null, 'changes' => [$change]];
            }
        }

        return $out;
    }
}
