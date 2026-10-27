<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappConversation;

class WhatsAppSoftwareEnquiry
{
    /** @var array<string, array{label:string, fields:list<array{key:string,label:string,ask:string}>}> */
    private const FLOWS = [
        'website' => [
            'label' => 'Website',
            'fields' => [
                ['key' => 'requirement', 'label' => 'Requirement', 'ask' => 'Website mein kya chahiye?'],
                ['key' => 'timeline', 'label' => 'Timeline', 'ask' => 'Kab tak chahiye?'],
                ['key' => 'budget', 'label' => 'Budget', 'ask' => 'Budget kitna hai?'],
            ],
        ],
        'app' => [
            'label' => 'Mobile app',
            'fields' => [
                ['key' => 'requirement', 'label' => 'Requirement', 'ask' => 'App mein kya chahiye?'],
                ['key' => 'timeline', 'label' => 'Timeline', 'ask' => 'Kab tak chahiye?'],
                ['key' => 'budget', 'label' => 'Budget', 'ask' => 'Budget kitna hai?'],
            ],
        ],
        'software' => [
            'label' => 'Custom software',
            'fields' => [
                ['key' => 'requirement', 'label' => 'Requirement', 'ask' => 'Software mein kya chahiye?'],
                ['key' => 'timeline', 'label' => 'Timeline', 'ask' => 'Kab tak chahiye?'],
                ['key' => 'budget', 'label' => 'Budget', 'ask' => 'Budget kitna hai?'],
            ],
        ],
    ];

    public function reply(WhatsappConversation $conversation, string $text): string
    {
        $text = trim($text);
        $state = is_array($conversation->bot_state) ? $conversation->bot_state : [];
        $collecting = ($state['flow'] ?? null) === 'software' && ($state['step'] ?? null) === 'collect';

        if ($this->isRestart($text) || ! $collecting) {
            $service = $this->choice($text);
            if ($service === null) {
                $conversation->update(['bot_state' => ['flow' => 'software', 'step' => 'menu']]);

                return $this->menu();
            }

            $conversation->update([
                'bot_state' => [
                    'flow' => 'software',
                    'step' => 'collect',
                    'service' => $service,
                    'index' => 0,
                    'answers' => [],
                ],
            ]);

            return self::FLOWS[$service]['label']." enquiry.\n\n".self::FLOWS[$service]['fields'][0]['ask'];
        }

        $service = (string) ($state['service'] ?? '');
        $flow = self::FLOWS[$service] ?? null;
        if ($flow === null) {
            $conversation->update(['bot_state' => ['flow' => 'software', 'step' => 'menu']]);

            return $this->menu();
        }

        $index = (int) ($state['index'] ?? 0);
        $field = $flow['fields'][$index] ?? null;
        $answers = is_array($state['answers'] ?? null) ? $state['answers'] : [];
        if ($field !== null && $text !== '') {
            $answers[$field['key']] = $text;
            $index++;
        }

        $next = $flow['fields'][$index] ?? null;
        if ($next !== null) {
            $conversation->update([
                'bot_state' => [
                    'flow' => 'software',
                    'step' => 'collect',
                    'service' => $service,
                    'index' => $index,
                    'answers' => $answers,
                ],
            ]);

            return $next['ask'];
        }

        $conversation->update(['bot_state' => ['flow' => 'software', 'step' => 'menu']]);

        $lines = [$flow['label']];
        foreach ($flow['fields'] as $field) {
            $lines[] = $field['label'].': '.($answers[$field['key']] ?? '');
        }

        return "Enquiry note ho gayi.\n\n".implode("\n", $lines)."\n\nTeam is number par call karegi. Nayi enquiry ke liye Hi likhein.";
    }

    public function menu(): string
    {
        return "Software enquiry.\n\nNumber reply karein:\n\n1. Website\n2. Mobile app\n3. Custom software";
    }

    private function isRestart(string $text): bool
    {
        return preg_match('/^(?:hi|hello|hey|menu|start|restart|namaste|enquiry|enquirys?)$/iu', $text) === 1;
    }

    private function choice(string $text): ?string
    {
        $value = mb_strtolower(trim($text));

        return match (true) {
            in_array($value, ['1', 'website', 'web', 'site'], true) => 'website',
            in_array($value, ['2', 'app', 'mobile', 'mobile app'], true) => 'app',
            in_array($value, ['3', 'software', 'custom', 'custom software'], true) => 'software',
            default => null,
        };
    }
}
