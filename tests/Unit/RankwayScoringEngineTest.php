<?php

namespace Tests\Unit;

use App\Services\Rankway\RankwayRankEngine;
use App\Services\Rankway\RankwayScoringEngine;
use PHPUnit\Framework\TestCase;

class RankwayScoringEngineTest extends TestCase
{
    public function test_score_weights_sum_to_hundred_scale(): void
    {
        $engine = new RankwayScoringEngine;
        $result = $engine->score([
            'visibility' => 100,
            'keywords' => 100,
            'backlinks' => 100,
            'referring' => 100,
            'technical' => 100,
            'performance' => 100,
            'content' => 100,
            'growth' => 100,
        ]);

        $this->assertSame(100, $result['score']);
        $this->assertSame(25, $result['breakdown']['visibility']['weight']);
    }

    public function test_partial_scores_are_weighted(): void
    {
        $engine = new RankwayScoringEngine;
        $result = $engine->score([
            'visibility' => 80,
            'keywords' => 50,
            'backlinks' => 40,
            'referring' => 40,
            'technical' => 70,
            'performance' => 60,
            'content' => 50,
            'growth' => 50,
        ]);

        $this->assertGreaterThan(40, $result['score']);
        $this->assertLessThan(80, $result['score']);
    }

    public function test_rank_sort_key_prefers_higher_traffic(): void
    {
        $engine = new RankwayRankEngine(new RankwayScoringEngine);
        $low = $engine->rankSortKey(['organic_traffic' => 100, 'organic_keywords' => 10]);
        $high = $engine->rankSortKey(['organic_traffic' => 100000, 'organic_keywords' => 10]);

        $this->assertGreaterThan($low, $high);
    }
}
