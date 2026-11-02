<?php

namespace App\Services\Commission;

use App\Models\MemberWallet;
use App\Models\PendingBillMargin;
use App\Services\Activity\ActivityLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Pending Billing Margin (user 2026-11-02): a dealer's legacy accrued billing margin — the
 * unpaid balance the dealer saw in legacy (ci_users.billmargin) — is NOT pushed into any
 * wallet at cut-over. Head Office sends it per branch (or in bulk) to the dealer's cash
 * wallet, the same way Pending Cash QR is decided. The payee is the branch's distributor
 * login → its member account. No TDS / service charge is withheld (it is a legacy balance,
 * already net in legacy), exactly like Pending Cash QR.
 */
class PendingBillMarginService
{
    public function send(PendingBillMargin $p, ?int $userId = null): PendingBillMargin
    {
        return DB::transaction(function () use ($p, $userId) {
            $p = PendingBillMargin::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($p->status === PendingBillMargin::STATUS_PENDING, 422, 'This margin was already decided.');
            $amount = round((float) $p->amount, 2);
            abort_if($amount <= 0, 422, 'Nothing to send.');
            $member = $p->payeeMember();
            abort_unless($member, 422, 'Branch "' . ($p->branch?->pickLabel() ?? $p->branch_id) . '" has no distributor login with a member account to credit.');

            $wallet = MemberWallet::lockForUpdate()->firstOrCreate(['member_id' => $member->id]);
            $wallet->increment('cash_balance', $amount);
            $p->forceFill(['status' => PendingBillMargin::STATUS_SENT, 'member_id' => $member->id, 'decided_by' => $userId, 'decided_at' => now()])->save();

            ActivityLogger::log('wallet', 'pending_bill_margin_sent',
                sprintf('Pending billing margin #%d → cash wallet: ₹%s to %s (branch #%d %s)', $p->id, Money::group($amount), $member->member_code, $p->branch_id, $p->branch?->name), [
                    'member' => $member->id, 'subject' => $p,
                    'details' => ['amount' => $amount, 'branch_id' => $p->branch_id, 'legacy_brid' => $p->branch?->legacy_brid, 'source' => $p->source],
                ]);

            return $p;
        });
    }

    public function reject(PendingBillMargin $p, ?int $userId = null, ?string $remarks = null): PendingBillMargin
    {
        return DB::transaction(function () use ($p, $userId, $remarks) {
            $p = PendingBillMargin::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($p->status === PendingBillMargin::STATUS_PENDING, 422, 'This margin was already decided.');
            $p->forceFill(['status' => PendingBillMargin::STATUS_REJECTED, 'decided_by' => $userId, 'decided_at' => now(), 'remarks' => $remarks ? mb_substr($remarks, 0, 255) : null])->save();
            ActivityLogger::log('wallet', 'pending_bill_margin_rejected',
                sprintf('Pending billing margin #%d rejected: ₹%s for branch #%d %s%s', $p->id, Money::group((float) $p->amount), $p->branch_id, $p->branch?->name, $remarks ? ' — ' . $remarks : ''),
                ['subject' => $p, 'details' => ['amount' => (float) $p->amount, 'remarks' => $remarks]]);

            return $p;
        });
    }
}
