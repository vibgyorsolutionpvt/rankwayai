<?php

namespace App\Services\Billing;

use App\Models\CreditRecharge;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

class CreditRechargeService
{
    public function __construct(
        private BillingService $billing,
        private CreditWalletService $wallet,
        private CashfreeClient $cashfree,
    ) {}

    /**
     * @return array{ok:bool, message:string, checkout_url?:string}
     */
    public function start(Workspace $workspace, User $user, string $packId, string $market): array
    {
        $pack = CreditPackCatalog::find($packId, $market);
        if (! $pack) {
            return ['ok' => false, 'message' => 'Invalid credit pack.'];
        }

        $recharge = CreditRecharge::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'pack_id' => $pack['id'],
            'credits' => $pack['credits'],
            'amount' => $pack['amount'],
            'currency' => $pack['currency'],
            'billing_market' => $market,
            'status' => 'pending',
        ]);

        return $this->cashfreeCheckout($workspace, $user, $recharge, $pack);
    }

    public function markPaid(CreditRecharge $recharge, string $provider, ?string $providerRef = null): void
    {
        if ($recharge->status === 'paid') {
            return;
        }

        $recharge->update([
            'status' => 'paid',
            'provider' => $provider,
            'provider_ref' => $providerRef ?: $recharge->provider_ref,
        ]);

        $this->wallet->addTopup($recharge->workspace, (int) $recharge->credits);
    }

    /**
     * When webhooks can't reach localhost, sync PAID links on billing page load.
     *
     * @return list<CreditRecharge>
     */
    public function syncPendingCashfree(Workspace $workspace, ?string $linkId = null): array
    {
        if (! $this->billing->cashfreeConfigured()) {
            return [];
        }

        $query = CreditRecharge::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'pending')
            ->where('provider', 'cashfree')
            ->whereNotNull('provider_ref')
            ->latest()
            ->limit(10);

        if (filled($linkId)) {
            $query->where('provider_ref', $linkId);
        }

        $paid = [];
        foreach ($query->get() as $recharge) {
            $link = $this->cashfree->getPaymentLink((string) $recharge->provider_ref);
            if (! ($link['ok'] ?? false)) {
                continue;
            }

            if (($link['status'] ?? '') === 'PAID' || (float) ($link['amount_paid'] ?? 0) > 0) {
                $this->markPaid($recharge, 'cashfree', $recharge->provider_ref);
                $paid[] = $recharge->fresh();
            }
        }

        return $paid;
    }

    /**
     * @param  array{id:string,credits:int,amount:float,currency:string}  $pack
     * @return array{ok:bool, message:string, checkout_url?:string}
     */
    private function cashfreeCheckout(Workspace $workspace, User $user, CreditRecharge $recharge, array $pack): array
    {
        if (! $this->billing->cashfreeConfigured()) {
            $this->markPaid($recharge, 'manual');

            return [
                'ok' => true,
                'message' => $pack['credits'].' credits added to your wallet.',
            ];
        }

        $linkId = 'cr_'.$recharge->id.'_'.Str::lower(Str::random(6));

        $result = $this->cashfree->createPaymentLink([
            'link_id' => $linkId,
            'amount' => (float) $pack['amount'],
            'currency' => (string) $pack['currency'],
            'purpose' => 'AI credits top-up: '.$pack['credits'].' credits',
            'customer_id' => 'ws_'.$workspace->id.'_u_'.$user->id,
            'customer_email' => $user->email,
            'customer_phone' => null,
            'customer_name' => $user->name,
            'return_url' => route('billing.index', ['recharge' => 'success']),
            'notes' => [
                'type' => 'credit_recharge',
                'recharge_id' => (string) $recharge->id,
                'workspace_id' => (string) $workspace->id,
                'credits' => (string) $pack['credits'],
            ],
        ]);

        if (! $result['ok']) {
            $recharge->update(['status' => 'failed']);

            return [
                'ok' => false,
                'message' => 'Couldn’t start recharge payment. Please try again.',
            ];
        }

        $recharge->update([
            'provider' => 'cashfree',
            'provider_ref' => $result['link_id'],
        ]);

        return [
            'ok' => true,
            'message' => 'Redirecting to payment…',
            'checkout_url' => $result['link_url'],
        ];
    }
}
