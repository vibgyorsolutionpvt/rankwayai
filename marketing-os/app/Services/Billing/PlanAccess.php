<?php

namespace App\Services\Billing;

use App\Models\Workspace;
use App\Models\WorkspaceSubscription;

class PlanAccess
{
    public function __construct(
        private BillingService $billing,
        private CreditWalletService $wallet,
    ) {}

    /**
     * @return array{plan:string,status:string,paid:bool,features:array<string,bool>}
     */
    public function summary(Workspace $workspace): array
    {
        $sub = $this->billing->subscription($workspace);
        $paid = $this->isPaid($sub);
        $topupCredits = $this->wallet->snapshot($workspace, $sub)['topup'];

        return [
            'plan' => $sub->plan,
            'status' => $sub->status,
            'paid' => $paid,
            'features' => $this->featuresFor($paid, $topupCredits),
        ];
    }

    /**
     * @return array<string, bool>
     */
    public function features(Workspace $workspace): array
    {
        return $this->summary($workspace)['features'];
    }

    public function allows(Workspace $workspace, string $feature): bool
    {
        $features = $this->features($workspace);

        return (bool) ($features[$feature] ?? false);
    }

    public function denyMessage(string $feature): string
    {
        return match ($feature) {
            'ai' => 'AI needs a paid plan or credit top-up. Upgrade or recharge credits to continue.',
            'channel_send' => 'Sending WhatsApp / Email / RCS needs a paid plan (messaging API). Upgrade to continue.',
            'seo_apis' => 'Google Search Console & PageSpeed need a paid plan.',
            'seo_metrics' => 'Keyword volume, difficulty, and live SERP ranks need a paid plan (DataForSEO).',
            'seo_local' => 'Local pack / Maps rank tracking needs a paid plan.',
            'seo_backlinks' => 'Backlink data needs a paid plan.',
            'seo_cms' => 'CMS autopublish needs a paid plan.',
            'seo_js_crawl' => 'JavaScript / advanced crawl needs a paid plan.',
            'social_oauth' => 'Live social connect (OAuth) needs a paid plan. Free can use sandbox accounts.',
            'api' => 'This action needs an external API and is not available on Free. Upgrade to continue.',
            default => 'This feature is not available on the Free plan. Upgrade to continue.',
        };
    }

    public function isPaid(?WorkspaceSubscription $sub): bool
    {
        if (! $sub) {
            return false;
        }

        if ($sub->plan === 'free') {
            return false;
        }

        return ! in_array($sub->status, ['cancelled', 'expired'], true);
    }

    /**
     * Free can use AI only by spending purchased top-up credits (pay-as-you-go).
     * Other API-heavy features still need a paid plan.
     *
     * @return array<string, bool>
     */
    private function featuresFor(bool $paid, int $topupCredits): array
    {
        return [
            'ai' => $paid || $topupCredits > 0,
            'api' => $paid,
            'channel_send' => $paid,
            'seo_apis' => $paid,
            'seo_metrics' => $paid,
            'seo_local' => $paid,
            'seo_backlinks' => $paid,
            'seo_cms' => $paid,
            'seo_js_crawl' => $paid,
            'social_oauth' => $paid,
        ];
    }
}
