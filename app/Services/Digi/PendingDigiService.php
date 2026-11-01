<?php

namespace App\Services\Digi;

use App\Models\DigiGoldTxn;
use App\Models\DigiOrder;
use App\Models\MemberWallet;
use App\Services\Activity\ActivityLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Pending Digi (board 2026-11-01, night): a distributor's legacy digi-gold balance (every
 * legacy credit minus every withdrawal) is imported as a digi_orders OPENING row and waits
 * there. Nothing reaches the purse until Head Office sends it: then ONE "legacy_opening"
 * line lands in the gram ledger (dated on the legacy date so the history reads right) and
 * member_wallets.digi_gold_grams rises by the same grams.
 */
class PendingDigiService
{
    public function credit(DigiOrder $o, ?int $userId = null): DigiOrder
    {
        return DB::transaction(function () use ($o, $userId) {
            $o = DigiOrder::whereKey($o->id)->lockForUpdate()->firstOrFail();
            abort_unless($o->status === DigiOrder::STATUS_PENDING, 422, 'This opening balance was already sent.');
            abort_unless($o->member_id, 422, 'This row has no distributor to credit.');
            $grams = round((float) $o->gold_wt, 4);
            abort_if($grams <= 0, 422, 'Nothing to send — the legacy balance is 0 g.');

            $wallet = MemberWallet::lockForUpdate()->firstOrCreate(['member_id' => $o->member_id]);
            $wallet->increment('digi_gold_grams', $grams);
            DigiGoldTxn::create([
                'member_id' => $o->member_id, 'metal' => 'gold', 'type' => 'credit',
                'grams' => $grams, 'rate' => (float) $o->rate_on_date, 'value' => round((float) $o->value, 2), 'fee' => 0,
                'source' => 'legacy_opening', 'reference' => $o->ref_no,
                'balance_after' => round((float) $wallet->fresh()->digi_gold_grams, 4),
                'created_at' => $o->created_at, 'updated_at' => now(),
            ]);
            $o->forceFill(['status' => DigiOrder::STATUS_CREDITED, 'decided_by' => $userId, 'decided_at' => now()])->save();

            ActivityLogger::log('wallet', 'pending_digi_credited',
                sprintf('Pending Digi #%d → purse: %.4f g (₹%s) for member #%d', $o->id, $grams, Money::group((float) $o->value), $o->member_id), [
                    'member' => $o->member_id, 'subject' => $o,
                    'details' => ['grams' => $grams, 'value' => (float) $o->value, 'rate' => (float) $o->rate_on_date, 'ref' => $o->ref_no],
                ]);

            return $o;
        });
    }
}
