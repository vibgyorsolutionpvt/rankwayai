<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappChatbot;
use App\Models\WhatsappConversation;
use App\Models\Workspace;

class WhatsAppChatbotService
{
    public function __construct(
        private WhatsAppConversationService $conversations,
        private WhatsAppTravelEnquiry $travel,
        private WhatsAppSoftwareEnquiry $software,
    ) {}

    public static function presetFor(Workspace $workspace): ?string
    {
        return match ($workspace->business_type) {
            'travel_agency', 'tour_operator', 'hotel' => 'travel',
            'it_software', 'digital_agency' => 'software',
            default => null,
        };
    }

    public function reply(Workspace $workspace, WhatsappConversation $conversation, string $text): void
    {
        $bot = WhatsappChatbot::query()->where('workspace_id', $workspace->id)->first();
        if (! $bot?->enabled) {
            return;
        }

        if ($conversation->assigned_user_id) {
            return;
        }

        if (WhatsappConversation::phoneIsOptedOut($workspace->id, $conversation->phone)) {
            return;
        }

        $reply = match ($bot->mode) {
            'travel' => $this->travel->reply($conversation, $text),
            'software' => $this->software->reply($conversation, $text),
            default => $this->match($bot, $text),
        };
        if ($reply === null) {
            return;
        }

        $result = $this->conversations->sendOutbound($workspace, $conversation, $reply);
        $message = $result['message'] ?? null;
        if ($message) {
            $message->update([
                'meta' => array_merge($message->meta ?? [], ['chatbot' => true]),
            ]);
        }
    }

    private function match(WhatsappChatbot $bot, string $text): ?string
    {
        foreach ($bot->rules ?? [] as $rule) {
            $keyword = trim((string) ($rule['keyword'] ?? ''));
            $reply = trim((string) ($rule['reply'] ?? ''));
            if ($keyword === '' || $reply === '' || ! $this->keywordMatches($text, $keyword)) {
                continue;
            }

            return $reply;
        }

        $fallback = trim((string) $bot->fallback);

        return $fallback !== '' ? $fallback : null;
    }

    private function keywordMatches(string $text, string $keyword): bool
    {
        if (preg_match('/\s/u', $keyword)) {
            return str_contains(mb_strtolower($text), mb_strtolower($keyword));
        }

        return preg_match('/\b'.preg_quote($keyword, '/').'\b/iu', $text) === 1;
    }
}
