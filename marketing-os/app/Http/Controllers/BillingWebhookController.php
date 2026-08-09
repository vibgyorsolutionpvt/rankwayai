<?php

namespace App\Http\Controllers;

use App\Models\CreditRecharge;
use App\Models\Workspace;
use App\Services\Billing\BillingService;
use App\Services\Billing\CreditRechargeService;
use App\Services\Billing\PlanCatalog;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BillingWebhookController extends Controller
{
    public function cashfree(
        Request $request,
        BillingService $billing,
        CreditRechargeService $recharges,
        \App\Services\Billing\CashfreeClient $cashfree
    ): Response {
        $payload = $request->getContent();
        $signature = $request->header('x-webhook-signature');
        $timestamp = $request->header('x-webhook-timestamp');

        if (! $cashfree->verifyWebhookSignature($payload, $signature, $timestamp)) {
            return response('Invalid signature', 400);
        }

        $event = json_decode($payload, true) ?: [];
        $type = (string) ($event['type'] ?? $event['event'] ?? '');
        $data = $event['data'] ?? [];

        $notes = $data['link_notes']
            ?? $data['order']['order_tags']
            ?? $data['payment']['payment_tags']
            ?? $data['order_tags']
            ?? [];

        // Payment link success payloads vary by API version.
        if (
            str_contains(strtoupper($type), 'PAYMENT') && str_contains(strtoupper($type), 'SUCCESS')
            || in_array($type, ['PAYMENT_SUCCESS_WEBHOOK', 'PAYMENT_LINK_EVENT'], true)
            || (($data['link']['link_status'] ?? null) === 'PAID')
            || (($data['payment']['payment_status'] ?? null) === 'SUCCESS')
        ) {
            $linkNotes = $data['link']['link_notes'] ?? $notes;
            if (is_array($linkNotes) && ($linkNotes['type'] ?? '') === 'credit_recharge') {
                $recharge = CreditRecharge::query()->find((int) ($linkNotes['recharge_id'] ?? 0));
                if ($recharge) {
                    $recharges->markPaid(
                        $recharge,
                        'cashfree',
                        $data['payment']['cf_payment_id']
                            ?? $data['link']['link_id']
                            ?? $linkNotes['recharge_id']
                            ?? null
                    );
                }

                return response('ok', 200);
            }

            if (is_array($linkNotes) && ($linkNotes['type'] ?? '') === 'plan_checkout') {
                $workspaceId = (int) ($linkNotes['workspace_id'] ?? 0);
                $plan = (string) ($linkNotes['plan'] ?? 'starter');
                $market = ($linkNotes['market'] ?? '') === PlanCatalog::MARKET_GLOBAL
                    ? PlanCatalog::MARKET_GLOBAL
                    : PlanCatalog::MARKET_IN;
                $interval = PlanCatalog::normalizeInterval($linkNotes['interval'] ?? PlanCatalog::INTERVAL_MONTH);
                $workspace = Workspace::query()->find($workspaceId);
                if ($workspace) {
                    $billing->applyCheckoutSuccess(
                        $workspace,
                        $plan,
                        $market,
                        'cashfree',
                        $data['customer_details']['customer_id'] ?? null,
                        $data['order']['order_id'] ?? $data['link']['link_id'] ?? null,
                        $interval
                    );
                }

                return response('ok', 200);
            }
        }

        return response('ok', 200);
    }

    public function stripe(Request $request, BillingService $billing, CreditRechargeService $recharges): Response
    {
        $payload = $request->getContent();
        $secret = config('services.stripe.webhook_secret');

        if (filled($secret)) {
            $sig = $request->header('Stripe-Signature', '');
            if (! $this->verifyStripeSignature($payload, $sig, $secret)) {
                return response('Invalid signature', 400);
            }
        }

        $event = json_decode($payload, true);
        $type = $event['type'] ?? '';
        $object = $event['data']['object'] ?? [];

        if ($type === 'checkout.session.completed') {
            $meta = $object['metadata'] ?? [];

            if (($meta['type'] ?? '') === 'credit_recharge') {
                $recharge = CreditRecharge::query()->find((int) ($meta['recharge_id'] ?? 0));
                if ($recharge) {
                    $recharges->markPaid($recharge, 'stripe', $object['id'] ?? null);
                }

                return response('ok', 200);
            }

            $workspaceId = (int) ($object['client_reference_id'] ?? $meta['workspace_id'] ?? 0);
            $plan = (string) ($meta['plan'] ?? 'starter');
            $interval = PlanCatalog::normalizeInterval($meta['interval'] ?? PlanCatalog::INTERVAL_MONTH);
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace) {
                $billing->applyCheckoutSuccess(
                    $workspace,
                    $plan,
                    PlanCatalog::MARKET_GLOBAL,
                    'stripe',
                    $object['customer'] ?? null,
                    $object['subscription'] ?? null,
                    $interval
                );
            }
        }

        if (in_array($type, ['customer.subscription.deleted', 'invoice.payment_failed'], true)) {
            $subId = $object['id'] ?? ($object['subscription'] ?? null);
            if ($subId) {
                $row = \App\Models\WorkspaceSubscription::query()
                    ->where('stripe_subscription_id', $subId)
                    ->first();
                if ($row) {
                    $billing->cancel($row->workspace);
                }
            }
        }

        return response('ok', 200);
    }

    public function razorpay(Request $request, BillingService $billing, CreditRechargeService $recharges): Response
    {
        $payload = $request->getContent();
        $secret = config('services.razorpay.webhook_secret');

        if (filled($secret)) {
            $sig = $request->header('X-Razorpay-Signature', '');
            $expected = hash_hmac('sha256', $payload, $secret);
            if (! hash_equals($expected, $sig)) {
                return response('Invalid signature', 400);
            }
        }

        $event = json_decode($payload, true);
        $eventName = $event['event'] ?? '';

        if (in_array($eventName, ['payment_link.paid', 'payment.captured'], true)) {
            $entity = $event['payload']['payment_link']['entity']
                ?? $event['payload']['payment']['entity']
                ?? [];
            $notes = $entity['notes'] ?? [];

            if (($notes['type'] ?? '') === 'credit_recharge') {
                $recharge = CreditRecharge::query()->find((int) ($notes['recharge_id'] ?? 0));
                if ($recharge) {
                    $recharges->markPaid($recharge, 'razorpay', $entity['id'] ?? null);
                }

                return response('ok', 200);
            }
        }

        $entity = $event['payload']['subscription']['entity']
            ?? $event['payload']['payment']['entity']
            ?? [];

        if (in_array($eventName, ['subscription.activated', 'subscription.charged'], true)) {
            $notes = $entity['notes'] ?? [];
            $workspaceId = (int) ($notes['workspace_id'] ?? 0);
            $plan = (string) ($notes['plan'] ?? 'starter');
            $interval = PlanCatalog::normalizeInterval($notes['interval'] ?? PlanCatalog::INTERVAL_MONTH);
            $workspace = Workspace::query()->find($workspaceId);
            if ($workspace) {
                $billing->applyCheckoutSuccess(
                    $workspace,
                    $plan,
                    PlanCatalog::MARKET_IN,
                    'razorpay',
                    $entity['customer_id'] ?? null,
                    $entity['id'] ?? null,
                    $interval
                );
            }
        }

        if (in_array($eventName, ['subscription.cancelled', 'subscription.completed', 'subscription.halted'], true)) {
            $subId = $entity['id'] ?? null;
            if ($subId) {
                $row = \App\Models\WorkspaceSubscription::query()
                    ->where('razorpay_subscription_id', $subId)
                    ->first();
                if ($row) {
                    $billing->cancel($row->workspace);
                }
            }
        }

        return response('ok', 200);
    }

    private function verifyStripeSignature(string $payload, string $header, string $secret): bool
    {
        // Stripe-Signature: t=timestamp,v1=signature
        $parts = [];
        foreach (explode(',', $header) as $item) {
            [$k, $v] = array_pad(explode('=', trim($item), 2), 2, null);
            if ($k && $v) {
                $parts[$k] = $v;
            }
        }

        if (empty($parts['t']) || empty($parts['v1'])) {
            return false;
        }

        $signed = $parts['t'].'.'.$payload;
        $expected = hash_hmac('sha256', $signed, $secret);

        return hash_equals($expected, $parts['v1']);
    }
}
