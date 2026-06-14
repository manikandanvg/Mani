<?php

namespace App\Http\Controllers;

use App\Models\RdEntry;
use App\Models\RdMandate;
use App\Services\Payment\RazorpaySubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Receives Razorpay subscription webhooks for RD auto-debit (e-mandate). On each successful
 * charge it records the RD installment DIRECTLY (RdEntry only) — e-mandate money is online,
 * so it spends no branch cash stock and earns no bill-margin (unlike the manual renewal,
 * which is left entirely untouched).
 */
class RazorpayWebhookController extends Controller
{
    public function __invoke(Request $request, RazorpaySubscriptionService $razorpay)
    {
        $raw = $request->getContent();
        if (! $razorpay->verifyWebhook($raw, $request->header('X-Razorpay-Signature'))) {
            return response()->json(['ok' => false, 'error' => 'invalid signature'], 400);
        }

        $event = (string) $request->input('event');
        $sub = $request->input('payload.subscription.entity', []);
        $subId = $sub['id'] ?? null;
        if (! $subId) {
            return response()->json(['ok' => true]);   // nothing actionable (e.g. order webhooks)
        }

        $mandate = RdMandate::where('razorpay_subscription_id', $subId)->first();
        if (! $mandate) {
            Log::info("Razorpay webhook for unknown subscription {$subId} ({$event})");

            return response()->json(['ok' => true]);
        }

        match ($event) {
            'subscription.charged' => $this->recordCharge($mandate, $sub, $request->input('payload.payment.entity', [])),
            'subscription.activated', 'subscription.authenticated' => $mandate->update(['status' => 'active']),
            'subscription.pending' => $mandate->update(['status' => 'pending']),
            'subscription.halted' => $mandate->update(['status' => 'halted']),
            'subscription.cancelled' => $mandate->update(['status' => 'cancelled']),
            'subscription.completed' => $mandate->update(['status' => 'completed']),
            default => null,
        };

        return response()->json(['ok' => true]);
    }

    /**
     * Record one auto-debited installment against the RD bond. Idempotent on the Razorpay
     * payment id (webhooks can be retried). No cash-stock deduction, no bill-margin.
     */
    protected function recordCharge(RdMandate $mandate, array $sub, array $payment): void
    {
        $paymentId = $payment['id'] ?? null;
        $processed = (array) ($mandate->meta['charged_payment_ids'] ?? []);
        if ($paymentId && in_array($paymentId, $processed, true)) {
            return;   // already recorded this charge
        }

        DB::transaction(function () use ($mandate, $sub, $payment, $paymentId, $processed) {
            $dueCount = RdEntry::where('bond_id', $mandate->bond_id)->count() + 1;

            RdEntry::create([
                'bond_id' => $mandate->bond_id,
                'member_id' => $mandate->member_id,
                'paid_on' => Carbon::now()->toDateString(),
                'value' => $mandate->amount,
                'due_count' => $dueCount,
                'branch_id' => $mandate->branch_id,   // the RD's own branch
            ]);

            if ($paymentId) {
                $processed[] = $paymentId;
            }
            $mandate->update([
                'status' => 'active',
                'paid_count' => (int) ($sub['paid_count'] ?? $mandate->paid_count + 1),
                'next_charge_on' => isset($sub['charge_at']) ? Carbon::createFromTimestamp((int) $sub['charge_at'])->toDateString() : $mandate->next_charge_on,
                'meta' => array_merge((array) $mandate->meta, ['charged_payment_ids' => $processed]),
            ]);
        });
    }
}
