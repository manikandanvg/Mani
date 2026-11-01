<?php

namespace App\Services\Digi;

use App\Models\DigiGoldTxn;
use App\Models\DigiQueue;
use App\Models\DigiWithdrawal;
use App\Models\MemberWallet;
use App\Services\Activity\ActivityLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Pending Cash QR (board 2026-11-01): a legacy digi-gold withdrawal request — the grams
 * already left the member's purse in legacy and a dealer was to pay cash at the counter —
 * is decided by Head Office per row:
 *
 *   toCash     → the rupee worth fixed on the request date goes to the member's CASH wallet
 *   toDigiGold → the grams go back to the member's digi-gold purse at the request-date rate
 *                (a credit line in the gram ledger), i.e. recorded as a digi-market purchase
 *
 * Either way the dealer-side ticket (digi_queue DIGIWD) is closed so no counter pays it too.
 * Nothing moves until HQ decides — the cut-over no longer settles these automatically.
 */
class PendingCashQrService
{
    public function toCash(DigiWithdrawal $w, ?int $userId = null): DigiWithdrawal
    {
        return $this->decide($w, DigiWithdrawal::DECISION_CASH, $userId, function (DigiWithdrawal $w) {
            $wallet = MemberWallet::lockForUpdate()->firstOrCreate(['member_id' => $w->member_id]);
            $wallet->increment('cash_balance', round((float) $w->worth, 2));
        });
    }

    public function toDigiGold(DigiWithdrawal $w, ?int $userId = null): DigiWithdrawal
    {
        return $this->decide($w, DigiWithdrawal::DECISION_DIGI, $userId, function (DigiWithdrawal $w) {
            $grams = round((float) $w->gold_wt, 4);
            abort_if($grams <= 0, 422, 'This request carries no grams — pay it to the cash wallet instead.');
            $wallet = MemberWallet::lockForUpdate()->firstOrCreate(['member_id' => $w->member_id]);
            $wallet->increment('digi_gold_grams', $grams);
            DigiGoldTxn::create([
                'member_id' => $w->member_id, 'metal' => 'gold', 'type' => 'credit',
                'grams' => $grams, 'rate' => (float) ($w->rate ?: ($grams > 0 ? round((float) $w->worth / $grams, 2) : 0)),
                'value' => round((float) $w->worth, 2), 'fee' => 0,
                'source' => 'legacy_cash_qr', 'reference' => 'WD-' . $w->id,
                'balance_after' => round((float) $wallet->fresh()->digi_gold_grams, 4),
            ]);
        });
    }

    protected function decide(DigiWithdrawal $w, string $decision, ?int $userId, \Closure $post): DigiWithdrawal
    {
        return DB::transaction(function () use ($w, $decision, $userId, $post) {
            $w = DigiWithdrawal::whereKey($w->id)->lockForUpdate()->firstOrFail();
            abort_unless($w->status === 'pending', 422, 'This request was already decided.');
            abort_unless($w->member_id, 422, 'This request has no distributor to credit.');

            $post($w);

            $w->forceFill(['status' => 'delivered', 'decision' => $decision, 'decided_by' => $userId, 'decided_at' => now()])->save();
            $this->closeTicket($w);

            $label = $decision === DigiWithdrawal::DECISION_CASH ? 'cash wallet' : 'digi-gold purse';
            ActivityLogger::log('wallet', 'pending_cash_qr_' . $decision,
                sprintf('Pending cash QR #%d → %s: %.4f g / ₹%s for member #%d', $w->id, $label, (float) $w->gold_wt, Money::group((float) $w->worth), $w->member_id), [
                    'member' => $w->member_id, 'subject' => $w,
                    'details' => ['grams' => (float) $w->gold_wt, 'worth' => (float) $w->worth, 'rate' => (float) $w->rate, 'qr_code' => $w->qr_code, 'source' => $w->source],
                ]);

            return $w;
        });
    }

    /** The dealer-side ticket for this request — by QR code when known, else member + grams. */
    protected function closeTicket(DigiWithdrawal $w): void
    {
        $q = DigiQueue::query()->where('route', 'DIGIWD')->where('delivery_status', 'pending');
        $q = $w->qr_code
            ? $q->where('qr_code', $w->qr_code)
            : $q->where('member_id', $w->member_id)->whereRaw('ABS(gram_worth - ?) < 0.0001', [(float) $w->gold_wt]);
        $q->orderBy('id')->limit(1)->update(['delivery_status' => 'redeemed', 'updated_at' => now()]);
    }
}
