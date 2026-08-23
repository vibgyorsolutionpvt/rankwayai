<?php

namespace App\Services\Festivals\Providers;

use App\Services\Festivals\FestivalEventData;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NagerPublicHolidayProvider
{
    /**
     * Free public holiday API — https://date.nager.at
     *
     * @return list<FestivalEventData>
     */
    public function forYear(int $year, string $countryCode = 'IN'): array
    {
        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->get("https://date.nager.at/api/v3/PublicHolidays/{$year}/{$countryCode}");

            if (! $response->successful()) {
                Log::warning('festivals.nager_failed', ['year' => $year, 'status' => $response->status()]);

                return [];
            }

            $rows = $response->json();
            if (! is_array($rows)) {
                return [];
            }

            $out = [];
            foreach ($rows as $row) {
                $name = trim((string) ($row['localName'] ?? $row['name'] ?? ''));
                $date = (string) ($row['date'] ?? '');
                if ($name === '' || $date === '') {
                    continue;
                }

                $out[] = new FestivalEventData(
                    name: $name,
                    occurs_on: $date,
                    category: 'holiday',
                    suggested_angles: ["{$name} — greeting or offer post", 'Share holiday hours if applicable'],
                    source: 'api',
                );
            }

            return $out;
        } catch (\Throwable $e) {
            Log::warning('festivals.nager_error', ['year' => $year, 'message' => $e->getMessage()]);

            return [];
        }
    }
}
