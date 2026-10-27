<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsappConversation;

class WhatsAppTravelEnquiry
{
    /** @var array<string, array{label:string, fields:list<array{key:string, ask:string}>}> */
    private const FLOWS = [
        'package' => [
            'label' => 'Tour package',
            'fields' => [
                ['key' => 'destination', 'label' => 'Destination', 'ask' => 'Destination kahan hai?'],
                ['key' => 'date', 'label' => 'Travel date', 'ask' => 'Travel date kya hai?'],
                ['key' => 'travellers', 'label' => 'Travellers', 'ask' => 'Kitne travellers hain?'],
            ],
        ],
        'transfer' => [
            'label' => 'Transfer',
            'fields' => [
                ['key' => 'pickup', 'label' => 'Pickup', 'ask' => 'Pickup kahan se hai?'],
                ['key' => 'drop', 'label' => 'Drop', 'ask' => 'Drop kahan hai?'],
                ['key' => 'when', 'label' => 'When', 'ask' => 'Date aur time kya hai?'],
                ['key' => 'passengers', 'label' => 'Passengers', 'ask' => 'Kitne passengers hain?'],
            ],
        ],
        'hotel' => [
            'label' => 'Hotel',
            'fields' => [
                ['key' => 'city', 'label' => 'City', 'ask' => 'Hotel kis city mein chahiye?'],
                ['key' => 'checkin', 'label' => 'Check-in', 'ask' => 'Check-in date?'],
                ['key' => 'checkout', 'label' => 'Check-out', 'ask' => 'Check-out date?'],
                ['key' => 'guests', 'label' => 'Guests', 'ask' => 'Kitne guests hain?'],
            ],
        ],
        'flight' => [
            'label' => 'Flight',
            'fields' => [
                ['key' => 'from', 'label' => 'From', 'ask' => 'Kis city se flight chahiye?'],
                ['key' => 'to', 'label' => 'To', 'ask' => 'Kis city tak?'],
                ['key' => 'date', 'label' => 'Travel date', 'ask' => 'Travel date kya hai?'],
                ['key' => 'passengers', 'label' => 'Passengers', 'ask' => 'Kitne passengers hain?'],
            ],
        ],
        'bus' => [
            'label' => 'Bus',
            'fields' => [
                ['key' => 'from', 'label' => 'From', 'ask' => 'Bus kis city se?'],
                ['key' => 'to', 'label' => 'To', 'ask' => 'Kis city tak?'],
                ['key' => 'date', 'label' => 'Travel date', 'ask' => 'Travel date kya hai?'],
                ['key' => 'seats', 'label' => 'Seats', 'ask' => 'Kitni seats chahiye?'],
            ],
        ],
    ];

    public function reply(WhatsappConversation $conversation, string $text): string
    {
        $text = trim($text);
        $state = is_array($conversation->bot_state) ? $conversation->bot_state : [];

        if ($this->isRestart($text) || ($state['step'] ?? null) !== 'collect') {
            $service = $this->choice($text);
            if ($service === null) {
                $conversation->update(['bot_state' => ['step' => 'menu']]);

                return $this->menu();
            }

            $conversation->update([
                'bot_state' => [
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
            $conversation->update(['bot_state' => ['step' => 'menu']]);

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
                    'step' => 'collect',
                    'service' => $service,
                    'index' => $index,
                    'answers' => $answers,
                ],
            ]);

            return $next['ask'];
        }

        $conversation->update(['bot_state' => ['step' => 'menu']]);

        return $this->summary($flow['label'], $flow['fields'], $answers);
    }

    public function menu(): string
    {
        return "Travel enquiry.\n\nNumber reply karein:\n\n1. Tour package\n2. Transfer\n3. Hotel\n4. Flight\n5. Bus";
    }

    private function isRestart(string $text): bool
    {
        return preg_match('/^(?:hi|hello|hey|menu|start|restart|namaste|enquiry|enquirys?)$/iu', $text) === 1;
    }

    private function choice(string $text): ?string
    {
        $value = mb_strtolower(trim($text));

        return match (true) {
            in_array($value, ['1', 'package', 'tour', 'tour package'], true) => 'package',
            in_array($value, ['2', 'transfer', 'cab', 'taxi'], true) => 'transfer',
            in_array($value, ['3', 'hotel', 'stay'], true) => 'hotel',
            in_array($value, ['4', 'flight', 'flights'], true) => 'flight',
            in_array($value, ['5', 'bus'], true) => 'bus',
            default => null,
        };
    }

    /**
     * @param  list<array{key:string, ask:string}>  $fields
     * @param  array<string, string>  $answers
     */
    private function summary(string $label, array $fields, array $answers): string
    {
        $lines = [$label];
        foreach ($fields as $field) {
            $lines[] = $field['label'].': '.($answers[$field['key']] ?? '');
        }

        return "Enquiry note ho gayi.\n\n".implode("\n", $lines)."\n\nTeam is number par call karegi. Nayi enquiry ke liye Hi likhein.";
    }
}
