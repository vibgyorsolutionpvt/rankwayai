<?php

namespace App\Services\Festivals\Providers;

use App\Services\Festivals\FestivalEventData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class IcsCalendarProvider
{
    /**
     * @return list<FestivalEventData>
     */
    public function forYear(string $feedUrl, int $year): array
    {
        if (trim($feedUrl) === '') {
            return [];
        }

        try {
            $response = Http::timeout(20)->get($feedUrl);
            if (! $response->successful()) {
                Log::warning('festivals.ics_failed', ['url' => $feedUrl, 'status' => $response->status()]);

                return [];
            }

            return $this->parse($response->body(), $year);
        } catch (\Throwable $e) {
            Log::warning('festivals.ics_error', ['url' => $feedUrl, 'message' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @return list<FestivalEventData>
     */
    private function parse(string $ics, int $year): array
    {
        $ics = str_replace("\r\n ", '', str_replace("\r", "\n", $ics));
        preg_match_all('/BEGIN:VEVENT\n(.*?)END:VEVENT/s', $ics, $blocks);

        $out = [];
        foreach ($blocks[1] as $block) {
            $summary = $this->lineValue($block, 'SUMMARY');
            $start = $this->lineValue($block, 'DTSTART');
            if ($summary === null || $start === null) {
                continue;
            }

            $date = $this->normalizeDate($start);
            if ($date === null || ! str_starts_with($date, (string) $year)) {
                continue;
            }

            $name = $this->decodeText($summary);
            if ($name === '') {
                continue;
            }

            $out[] = new FestivalEventData(
                name: $name,
                occurs_on: $date,
                category: $this->guessCategory($name),
                suggested_angles: ["{$name} — timely social post", 'Festival greeting + CTA'],
                source: 'ics',
            );
        }

        return $out;
    }

    private function lineValue(string $block, string $key): ?string
    {
        if (preg_match('/^'.preg_quote($key, '/').'(?:;[^:]*)?:(.+)$/m', $block, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function normalizeDate(string $raw): ?string
    {
        if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $raw, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return null;
    }

    private function decodeText(string $value): string
    {
        $value = str_replace('\\,', ',', $value);
        $value = str_replace('\\n', ' ', $value);

        return trim($value);
    }

    private function guessCategory(string $name): string
    {
        $lower = mb_strtolower($name);
        if (str_contains($lower, 'sale') || str_contains($lower, 'offer')) {
            return 'sale';
        }

        return 'festival';
    }
}
