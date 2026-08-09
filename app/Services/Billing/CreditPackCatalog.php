<?php

namespace App\Services\Billing;

class CreditPackCatalog
{
    /**
     * @return list<array{id:string,credits:int,amount:float,currency:string,label:string}>
     */
    public static function packsForMarket(string $market): array
    {
        if ($market === PlanCatalog::MARKET_IN) {
            return [
                ['id' => 'in_500', 'credits' => 500, 'amount' => 199, 'currency' => 'INR', 'symbol' => '₹', 'label' => '500 credits'],
                ['id' => 'in_2000', 'credits' => 2000, 'amount' => 699, 'currency' => 'INR', 'symbol' => '₹', 'label' => '2,000 credits'],
                ['id' => 'in_5000', 'credits' => 5000, 'amount' => 1499, 'currency' => 'INR', 'symbol' => '₹', 'label' => '5,000 credits'],
            ];
        }

        return [
            ['id' => 'gl_500', 'credits' => 500, 'amount' => 5, 'currency' => 'USD', 'symbol' => '$', 'label' => '500 credits'],
            ['id' => 'gl_2000', 'credits' => 2000, 'amount' => 15, 'currency' => 'USD', 'symbol' => '$', 'label' => '2,000 credits'],
            ['id' => 'gl_5000', 'credits' => 5000, 'amount' => 35, 'currency' => 'USD', 'symbol' => '$', 'label' => '5,000 credits'],
        ];
    }

    public static function find(string $packId, string $market): ?array
    {
        foreach (self::packsForMarket($market) as $pack) {
            if ($pack['id'] === $packId) {
                return $pack;
            }
        }

        return null;
    }

    /** Convert internal USD cost to whole credits (min 1). */
    public static function costToCredits(float $costUsd): int
    {
        return max(1, (int) round($costUsd * 100));
    }

    public static function creditsToUsd(int $credits): float
    {
        return round($credits / 100, 4);
    }
}
