<?php

namespace App\Services\Rankway;

/**
 * Computes Rankway Score (0–100) from weighted SEO / visibility signals.
 */
class RankwayScoringEngine
{
    /**
     * @param  array{
     *   visibility?:int|null,
     *   keywords?:int|null,
     *   backlinks?:int|null,
     *   referring?:int|null,
     *   technical?:int|null,
     *   performance?:int|null,
     *   content?:int|null,
     *   growth?:int|null
     * }  $parts  Each value 0–100 (factor score before weight)
     * @return array{score:int,breakdown:array<string,array{weight:int,score:int,points:float}>}
     */
    public function score(array $parts): array
    {
        $weights = [
            'visibility' => 25,
            'keywords' => 20,
            'backlinks' => 15,
            'referring' => 10,
            'technical' => 10,
            'performance' => 10,
            'content' => 5,
            'growth' => 5,
        ];

        $breakdown = [];
        $total = 0.0;

        foreach ($weights as $key => $weight) {
            $factor = $this->clamp((int) ($parts[$key] ?? 50));
            $points = ($factor / 100) * $weight;
            $breakdown[$key] = [
                'weight' => $weight,
                'score' => $factor,
                'points' => round($points, 2),
            ];
            $total += $points;
        }

        return [
            'score' => (int) round(min(100, max(0, $total))),
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Map raw counts onto 0–100 with log-ish curves so giants don't crush everyone.
     */
    public function scoreFromCount(?int $count, int $softCap): int
    {
        $count = max(0, (int) $count);
        if ($count === 0) {
            return 8;
        }

        $ratio = min(1.0, log(1 + $count) / log(1 + max(1, $softCap)));

        return $this->clamp((int) round(8 + ($ratio * 92)));
    }

    private function clamp(int $value): int
    {
        return max(0, min(100, $value));
    }
}
