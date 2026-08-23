<?php

namespace App\Console\Commands;

use App\Services\Rankway\RankwayRankEngine;
use Illuminate\Console\Command;

class RecomputeRankwayRanksCommand extends Command
{
    protected $signature = 'rankway:recompute-ranks';

    protected $description = 'Recompute estimated Rankway global/country/category ranks among indexed domains';

    public function handle(RankwayRankEngine $ranks): int
    {
        $result = $ranks->recomputeAll();
        $this->info('Updated '.$result['updated'].' domain rank(s).');

        return self::SUCCESS;
    }
}
