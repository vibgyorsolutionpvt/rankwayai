<?php

namespace App\Services\Billing;

class PlanCatalog
{
    public const MARKET_IN = 'in';

    public const MARKET_GLOBAL = 'global';

    public const INTERVAL_MONTH = 'month';

    public const INTERVAL_YEAR = 'year';

    /**
     * @return list<string>
     */
    public static function planIds(): array
    {
        return ['free', 'starter', 'growth', 'agency'];
    }

    /**
     * @return list<string>
     */
    public static function intervals(): array
    {
        return [self::INTERVAL_MONTH, self::INTERVAL_YEAR];
    }

    public static function normalizeInterval(?string $interval): string
    {
        return $interval === self::INTERVAL_YEAR
            ? self::INTERVAL_YEAR
            : self::INTERVAL_MONTH;
    }

    /**
     * @return array{currency:string, symbol:string, gateway:string, label:string}
     */
    public static function market(string $market): array
    {
        return match ($market) {
            self::MARKET_IN => [
                'currency' => 'INR',
                'symbol' => '₹',
                'gateway' => 'cashfree',
                'label' => 'India (₹ · Cashfree)',
            ],
            default => [
                'currency' => 'USD',
                'symbol' => '$',
                'gateway' => 'cashfree',
                'label' => 'Global ($ · Cashfree)',
            ],
        };
    }

    /**
     * Monthly list prices by market.
     *
     * @return array<string, int|float>
     */
    public static function monthlyPrices(string $market): array
    {
        return $market === self::MARKET_IN
            ? [
                'free' => 0,
                'starter' => 2499,
                'growth' => 6999,
                'agency' => 16999,
            ]
            : [
                'free' => 0,
                'starter' => 29,
                'growth' => 79,
                'agency' => 199,
            ];
    }

    /**
     * Yearly billed amount (≈2 months free vs paying monthly).
     *
     * @return array<string, int|float>
     */
    public static function yearlyPrices(string $market): array
    {
        return $market === self::MARKET_IN
            ? [
                'free' => 0,
                'starter' => 24990,
                'growth' => 69990,
                'agency' => 169990,
            ]
            : [
                'free' => 0,
                'starter' => 290,
                'growth' => 790,
                'agency' => 1990,
            ];
    }

    /**
     * How many workspaces an account plan may cover.
     */
    public static function workspaceLimit(string $plan): int
    {
        return match ($plan) {
            'starter' => 2,
            'growth' => 5,
            'agency' => 50,
            default => 1,
        };
    }

    public static function planRank(string $plan): int
    {
        return match ($plan) {
            'agency' => 4,
            'growth' => 3,
            'starter' => 2,
            default => 1,
        };
    }

    /**
     * Bullet points shown on pricing / billing cards (keep Free in sync with Current Plan).
     *
     * @return list<string>
     */
    public static function highlights(string $plan, string $market = self::MARKET_IN): array
    {
        return match ($plan) {
            'free' => [
                '1 workspace',
                'SEO site audit crawl',
                'Billing & workspace settings',
                'No external APIs (GSC, PageSpeed, social…)',
            ],
            'starter' => [
                'Everything in Free',
                '2 workspaces',
                'GSC, PageSpeed & keyword APIs',
                'AI studio with plan credits',
                'Social publish + WhatsApp / Email / RCS',
                '500 channel sends/mo',
            ],
            'growth' => [
                'Everything in Starter',
                '5 workspaces',
                'Higher AI budget',
                '5,000 channel sends/mo',
                'Backlinks, local pack, CMS & JS crawl',
            ],
            'agency' => [
                'Everything in Growth',
                '50 workspaces',
                'Max AI budget',
                '50,000 channel sends/mo',
                'Best for agencies & multi-brand teams',
            ],
            default => [],
        };
    }

    /**
     * Display + checkout amounts for a market + billing interval.
     *
     * @return list<array{
     *   id:string,
     *   name:string,
     *   price:int|float,
     *   price_monthly_equiv:int|float,
     *   blurb:string,
     *   highlights:list<string>,
     *   currency:string,
     *   symbol:string,
     *   interval:string,
     *   save_label:?string
     * }>
     */
    public static function plansForMarket(string $market, string $interval = self::INTERVAL_MONTH): array
    {
        $interval = self::normalizeInterval($interval);
        $meta = self::market($market);
        $monthly = self::monthlyPrices($market);
        $yearly = self::yearlyPrices($market);
        $isYear = $interval === self::INTERVAL_YEAR;

        $blurbs = [
            'free' => 'SEO crawl on us — upgrade when you need APIs, channels & AI',
            'starter' => 'Full product access for up to 2 workspaces',
            'growth' => 'More workspaces, AI budget, and channel volume',
            'agency' => 'Scale across many brands and high send volume',
        ];

        $names = [
            'free' => 'Free',
            'starter' => 'Starter',
            'growth' => 'Growth',
            'agency' => 'Agency',
        ];

        $out = [];
        foreach (self::planIds() as $id) {
            $monthPrice = $monthly[$id];
            $yearPrice = $yearly[$id];
            $price = $isYear ? $yearPrice : $monthPrice;
            $monthlyEquiv = $isYear && $id !== 'free'
                ? round($yearPrice / 12, $market === self::MARKET_IN ? 0 : 2)
                : $monthPrice;

            $out[] = [
                'id' => $id,
                'name' => $names[$id],
                'price' => $price,
                'price_monthly_equiv' => $monthlyEquiv,
                'blurb' => $blurbs[$id],
                'highlights' => self::highlights($id, $market),
                'currency' => $meta['currency'],
                'symbol' => $meta['symbol'],
                'interval' => $interval,
                'save_label' => $isYear && $id !== 'free' ? '2 months free' : null,
            ];
        }

        return $out;
    }

    public static function price(
        string $plan,
        string $market,
        string $interval = self::INTERVAL_MONTH
    ): float {
        $interval = self::normalizeInterval($interval);
        $prices = $interval === self::INTERVAL_YEAR
            ? self::yearlyPrices($market)
            : self::monthlyPrices($market);

        return (float) ($prices[$plan] ?? 0);
    }

    /** Monthly USD equivalent used for internal mrr_usd reporting. */
    public static function mrrUsd(string $plan, string $interval = self::INTERVAL_MONTH): float
    {
        $monthly = match ($plan) {
            'growth' => 79.0,
            'agency' => 199.0,
            'free' => 0.0,
            default => 29.0,
        };

        if (self::normalizeInterval($interval) === self::INTERVAL_YEAR && $plan !== 'free') {
            // Yearly billed as 10× monthly → effective MRR is 10/12 of list.
            return round($monthly * (10 / 12), 2);
        }

        return $monthly;
    }
}
