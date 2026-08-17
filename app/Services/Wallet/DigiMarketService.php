<?php

namespace App\Services\Wallet;

use App\Models\DigiGoldPurchase;
use App\Models\DigiGoldTxn;
use App\Models\LiveRate;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\Setting;
use App\Services\Payment\RazorpayService;
use App\Services\Push\Notifier;
use Illuminate\Support\Facades\DB;

/**
 * Digi Market (board 2026-08-11) — the two-metal INVESTMENT purse.
 *
 * Flow: cash wallet → metal → cash wallet, nothing else.
 *  Buy:      by amount or by weight, funded from the cash wallet (instant) or
 *            online via Razorpay (grams credit on verified payment, at the rate
 *            snapshotted when the purchase was created).
 *  Withdraw: metal → cash wallet at the LIVE rate, minus the admin-configured
 *            platform fee (settings group 'digi_market').
 *
 * Scan & Pay is retired — metal can no longer be spent at a branch counter.
 * All balance mutations lock the wallet row and land in digi_gold_txns with a
 * running balance, so the gram columns are always reconstructible.
 */
class DigiMarketService
{
    /** Minimum buy / withdraw value — keeps gateway fees and dust sensible. */
    public const MIN_BUY_INR = 100;

    public const METALS = ['gold', 'silver'];

    /** Fallback platform fee (%) when admin has never configured one. */
    public const DEFAULT_FEE_PCT = 1.0;

    public function __construct(protected RazorpayService $razorpay)
    {
    }

    /** Live INR-per-gram rate for a metal; refuses to trade without one. */
    public function rate(string $metal): float
    {
        $this->assertMetal($metal);

        $rate = (float) (LiveRate::latestFor('IN')->{$metal} ?? 0);
        if ($rate <= 0) {
            throw new \RuntimeException('Live ' . $metal . ' rate unavailable — please try again shortly.');
        }

        return $rate;
    }

    /** Platform fee % on metal → cash-wallet transfers (Commission Setup → Digi Market). */
    public static function platformFeePct(): float
    {
        $row = Setting::where('group', 'digi_market')->where('key', 'platform_fee_pct')->value('value');

        return $row !== null ? (float) $row : self::DEFAULT_FEE_PCT;
    }

    /**
     * Two-way quote: give amount OR grams, get the other at the live rate.
     *
     * @return array{metal: string, rate: float, amount: float, grams: float}
     */
    public function quote(string $metal, ?float $amount = null, ?float $grams = null): array
    {
        $rate = $this->rate($metal);

        if ($grams === null && $amount === null) {
            throw new \InvalidArgumentException('Give an amount or a weight.');
        }
        if ($grams === null) {
            $grams = round($amount / $rate, 4);
        } else {
            $amount = round($grams * $rate, 2);
        }

        return ['metal' => $metal, 'rate' => $rate, 'amount' => round($amount, 2), 'grams' => $grams];
    }

    /**
     * Withdraw preview: what a metal → cash transfer is worth after the platform fee.
     *
     * @return array{metal: string, rate: float, amount: float, grams: float, fee_pct: float, fee: float, net: float}
     */
    public function withdrawQuote(string $metal, ?float $amount = null, ?float $grams = null): array
    {
        $q = $this->quote($metal, $amount, $grams);
        $pct = self::platformFeePct();
        $fee = round($q['amount'] * $pct / 100, 2);

        return $q + ['fee_pct' => $pct, 'fee' => $fee, 'net' => round($q['amount'] - $fee, 2)];
    }

    /**
     * Start a buy. Wallet funding settles instantly (cash out, grams in, one
     * transaction); online funding returns a purchase awaiting Razorpay payment.
     */
    public function beginPurchase(Member $member, string $metal, float $amount, string $funding = 'online'): DigiGoldPurchase
    {
        if (! in_array($funding, ['online', 'wallet'], true)) {
            throw new \InvalidArgumentException('Unknown funding method.');
        }
        if ($amount < self::MIN_BUY_INR) {
            throw new \InvalidArgumentException('Minimum purchase is ₹' . \App\Support\Money::group(self::MIN_BUY_INR, 0) . '.');
        }

        $quote = $this->quote($metal, $amount);

        if ($funding === 'wallet') {
            return $this->purchaseFromWallet($member, $quote);
        }

        if (! $this->razorpay->configured()) {
            throw new \RuntimeException('Online payment is not enabled yet — please pay from your wallet instead.');
        }

        $purchase = DigiGoldPurchase::create([
            'member_id' => $member->id,
            'metal' => $metal,
            'funding' => 'online',
            'amount' => $quote['amount'],
            'rate' => $quote['rate'],
            'grams' => $quote['grams'],
            'status' => 'created',
        ]);

        $rzp = $this->razorpay->createOrder($quote['amount'], 'DG-' . $purchase->id, [
            'digi_gold_purchase_id' => (string) $purchase->id,
            'member_code' => (string) $member->member_code,
        ]);
        if (! $rzp) {
            $purchase->update(['status' => 'failed']);
            throw new \RuntimeException('Could not start the payment — please try again.');
        }

        $purchase->update(['razorpay_order_id' => $rzp['id']]);

        return $purchase->refresh();
    }

    /** Cash wallet → metal in one locked transaction (no gateway involved). */
    protected function purchaseFromWallet(Member $member, array $quote): DigiGoldPurchase
    {
        $purchase = DB::transaction(function () use ($member, $quote) {
            $wallet = MemberWallet::lockForUpdate()->find($member->id);

            if (! $wallet || (float) $wallet->cash_balance < $quote['amount']) {
                throw new \InvalidArgumentException(sprintf(
                    'Insufficient wallet balance — you need ₹%s, available ₹%s.',
                    \App\Support\Money::group($quote['amount']),
                    \App\Support\Money::group((float) ($wallet->cash_balance ?? 0)),
                ));
            }

            $wallet->decrement('cash_balance', $quote['amount']);

            $purchase = DigiGoldPurchase::create([
                'member_id' => $member->id,
                'metal' => $quote['metal'],
                'funding' => 'wallet',
                'amount' => $quote['amount'],
                'rate' => $quote['rate'],
                'grams' => $quote['grams'],
                'status' => 'paid',
                'paid_at' => now(),
            ]);

            $this->creditLocked($member->id, $quote['metal'], $quote['grams'], $quote['rate'], 'buy_wallet', 'DG-' . $purchase->id);

            return $purchase;
        });

        $this->notifyCredited($purchase, fromWallet: true);

        return $purchase;
    }

    /** Checkout-JS verify path: check the signature, then credit. */
    public function completePurchase(DigiGoldPurchase $purchase, string $paymentId, string $signature): DigiGoldPurchase
    {
        if ($purchase->status === 'paid') {
            return $purchase;   // already settled (webhook may have beaten the browser)
        }

        $valid = $purchase->razorpay_order_id
            && $this->razorpay->verifySignature($purchase->razorpay_order_id, $paymentId, $signature);

        if (! $valid) {
            $purchase->update(['status' => 'failed', 'razorpay_payment_id' => $paymentId]);
            throw new \RuntimeException('Payment could not be verified. No ' . $purchase->metal . ' was credited.');
        }

        return $this->settlePurchase($purchase, $paymentId);
    }

    /** Webhook path (payload already HMAC-verified): credit by gateway order id. */
    public function settleByGatewayOrder(string $razorpayOrderId, ?string $paymentId = null): ?DigiGoldPurchase
    {
        $purchase = DigiGoldPurchase::where('razorpay_order_id', $razorpayOrderId)->first();
        if (! $purchase || $purchase->status === 'paid') {
            return $purchase;
        }

        return $this->settlePurchase($purchase, $paymentId);
    }

    /** Idempotent gram credit — locks the wallet, writes the ledger, notifies. */
    protected function settlePurchase(DigiGoldPurchase $purchase, ?string $paymentId): DigiGoldPurchase
    {
        DB::transaction(function () use ($purchase, $paymentId) {
            // Re-read under lock so a webhook + browser-verify race credits exactly once.
            $fresh = DigiGoldPurchase::lockForUpdate()->find($purchase->id);
            if ($fresh->status === 'paid') {
                return;
            }

            $this->creditLocked($fresh->member_id, $fresh->metal, (float) $fresh->grams, (float) $fresh->rate, 'buy', 'DG-' . $fresh->id);

            $fresh->update([
                'status' => 'paid',
                'razorpay_payment_id' => $paymentId ?: $fresh->razorpay_payment_id,
                'paid_at' => now(),
            ]);

            DB::afterCommit(fn () => $this->notifyCredited($fresh));
        });

        return $purchase->refresh();
    }

    /**
     * Metal → cash wallet at the live rate, minus the platform fee. Give grams
     * OR amount (₹ value to release); the member saw the exact fee + net first
     * via withdrawQuote.
     */
    public function withdrawToWallet(Member $member, string $metal, ?float $grams = null, ?float $amount = null): DigiGoldTxn
    {
        $q = $this->withdrawQuote($metal, $amount, $grams);

        if ($q['amount'] < self::MIN_BUY_INR) {
            throw new \InvalidArgumentException('Minimum transfer is ₹' . \App\Support\Money::group(self::MIN_BUY_INR, 0) . '.');
        }

        $column = $this->gramsColumn($metal);

        $txn = DB::transaction(function () use ($member, $metal, $column, $q) {
            $wallet = MemberWallet::lockForUpdate()->find($member->id);

            if (! $wallet || (float) $wallet->{$column} < $q['grams']) {
                throw new \InvalidArgumentException(sprintf(
                    'Insufficient Digi %s — you need %s g, available %s g.',
                    ucfirst($metal),
                    number_format($q['grams'], 4),
                    number_format((float) ($wallet->{$column} ?? 0), 4),
                ));
            }

            $wallet->decrement($column, $q['grams']);
            $wallet->increment('cash_balance', $q['net']);

            return DigiGoldTxn::create([
                'member_id' => $member->id,
                'metal' => $metal,
                'type' => 'debit',
                'grams' => $q['grams'],
                'rate' => $q['rate'],
                'value' => $q['amount'],
                'fee' => $q['fee'],
                'source' => 'withdraw',
                'reference' => null,
                'balance_after' => (float) $wallet->fresh()->{$column},
            ]);
        });

        Notifier::to($member, 'wallet',
            'Digi ' . ucfirst($metal) . ' transferred to wallet',
            number_format($q['grams'], 4) . ' g sold at ₹' . \App\Support\Money::group($q['rate']) . '/g — ₹'
                . \App\Support\Money::group($q['net']) . ' credited to your cash wallet'
                . ($q['fee'] > 0 ? ' (platform fee ₹' . \App\Support\Money::group($q['fee']) . ')' : '') . '.',
            route: '/wallet',
        );

        return $txn;
    }

    /** Credit grams under a wallet lock + write the ledger row. Returns the new balance. */
    protected function creditLocked(int $memberId, string $metal, float $grams, float $rate, string $source, string $reference): float
    {
        $column = $this->gramsColumn($metal);

        $wallet = MemberWallet::lockForUpdate()->find($memberId)
            ?? MemberWallet::create(['member_id' => $memberId]);

        $wallet->increment($column, $grams);
        $balance = (float) $wallet->fresh()->{$column};

        DigiGoldTxn::create([
            'member_id' => $memberId,
            'metal' => $metal,
            'type' => 'credit',
            'grams' => $grams,
            'rate' => $rate,
            'value' => round($grams * $rate, 2),
            'source' => $source,
            'reference' => $reference,
            'balance_after' => $balance,
        ]);

        return $balance;
    }

    protected function notifyCredited(DigiGoldPurchase $purchase, bool $fromWallet = false): void
    {
        $column = $this->gramsColumn($purchase->metal);
        $balance = (float) (MemberWallet::find($purchase->member_id)?->{$column} ?? 0);

        Notifier::to($purchase->member, 'wallet',
            'Digi ' . ucfirst($purchase->metal) . ' purchased',
            number_format((float) $purchase->grams, 4) . ' g credited for ₹' . \App\Support\Money::group((float) $purchase->amount)
                . ($fromWallet ? ' from your cash wallet' : '')
                . ' at ₹' . \App\Support\Money::group((float) $purchase->rate) . '/g. Balance: ' . number_format($balance, 4) . ' g.',
            route: '/digimarket',
        );
    }

    public function gramsColumn(string $metal): string
    {
        $this->assertMetal($metal);

        return $metal === 'gold' ? 'digi_gold_grams' : 'digi_silver_grams';
    }

    protected function assertMetal(string $metal): void
    {
        if (! in_array($metal, self::METALS, true)) {
            throw new \InvalidArgumentException('Unknown metal — choose gold or silver.');
        }
    }
}
