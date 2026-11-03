<?php

namespace App\Services\Lbox;

use App\Models\BranchPayoutRequest;
use App\Models\Device;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\User;
use App\Models\WalletWithdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Closed-wallet withdrawal through the branch L-BOX static QR. The QR printed on the
 * box carries the device UUID; scanning it in the app targets that box's branch.
 * Requesting debits the member's wallet immediately (and the box announces it).
 *
 * Board 2026-11-03 — the branch never fronts the cash:
 *   pending    the member's request sits at the branch
 *   requested  the branch bundled rows into a BranchPayoutRequest for Head Office
 *   admin_paid HQ transferred the bundle to the branch (payRequest) — Disburse appears
 *   disbursed  the branch handed over the cash/gold at the counter
 * Only HQ cancels (cancel / cancelRequest); a cancel refunds the pot the row debited.
 * HQ may also disburse a pending row directly (its own branch's counter).
 * No payment gateway anywhere.
 */
class WalletWithdrawalService
{
    public function __construct(protected AnnouncementService $announcements)
    {
    }

    /** Resolve a scanned QR (device uuid) → the box + branch the money would go to. */
    public function resolveQr(string $deviceUuid): Device
    {
        $device = Device::where('uuid', $deviceUuid)->where('status', 'active')->first();

        if (! $device || ! $device->branch_id) {
            throw new \RuntimeException('This QR does not belong to an active branch L-BOX.');
        }

        // Static payment QR (no box, board 2026-10-31): the box-based controls below cannot
        // apply — the dealer handles the cash payout at the counter.
        if ($device->isStaticQr()) {
            return $device;
        }

        // Branch-offline controls: a moved box or an unopened branch takes no money.
        if ($device->is_displaced && ! $device->isRoaming()) {
            throw new \RuntimeException('This L-BOX has been moved from its branch — withdrawals are suspended. Contact Head Office.');
        }
        if (! \App\Models\BranchAttendance::isOpenToday($device->branch_id)) {
            throw new \RuntimeException('This branch has not opened today — withdrawals resume once the incharge checks in at the L-BOX.');
        }

        return $device;
    }

    /** Move wallet balance to the branch. Atomic: debit + row + voice line. */
    public function request(Member $member, string $deviceUuid, float $amount): WalletWithdrawal
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Enter an amount above zero.');
        }

        $device = $this->resolveQr($deviceUuid);

        $withdrawal = DB::transaction(function () use ($member, $device, $amount) {
            // Lock the wallet row so two simultaneous scans cannot overdraw.
            $wallet = MemberWallet::lockForUpdate()->find($member->id);

            if (! $wallet || (float) $wallet->cash_balance < $amount) {
                throw new \InvalidArgumentException(sprintf(
                    'Insufficient wallet balance — available ₹%s.',
                    \App\Support\Money::group((float) ($wallet->cash_balance ?? 0)),
                ));
            }

            $wallet->decrement('cash_balance', $amount);
            $wallet->increment('withdrawn_total', $amount);

            $withdrawal = WalletWithdrawal::create([
                'member_id' => $member->id,
                'branch_id' => $device->branch_id,
                'device_id' => $device->id,
                'amount' => $amount,
                'status' => 'pending',
            ]);

            $line = ($device->language ?? 'en') === 'ta'
                ? sprintf('ரூபாய் %s, %s அவர்களிடமிருந்து பெறப்பட்டது. கவுண்டரில் வழங்கவும்.', \App\Support\Money::group($amount, 0), $member->name)
                : sprintf('Rupees %s received from %s. Please disburse at the counter.', \App\Support\Money::group($amount, 0), $member->name);

            $this->announcements->queue($device, 'withdrawal', $line, [
                'withdrawal_id' => $withdrawal->id,
                'amount' => $amount,
                'member_code' => $member->member_code,
            ]);

            return $withdrawal;
        });

        // L-BOX scan → amount acknowledgement to the member (board 2026-08-11).
        \App\Services\Push\Notifier::to($member, 'wallet',
            'Withdrawal request received',
            \App\Support\Money::inr($amount) . ' withdrawal requested at '
                . ($withdrawal->branch?->name ?? 'the branch') . '. Collect at the counter once disbursed.',
            route: '/wallet',
            data: ['withdrawal_id' => (string) $withdrawal->id],
        );

        // …and alert HQ admins + the branch's own dealer that cash is being asked for.
        $branchName = $withdrawal->branch?->name ?? 'branch #' . $withdrawal->branch_id;
        \App\Services\Push\Notifier::admins(
            'Withdrawal request — ' . $branchName,
            $member->member_code . ' requested ₹' . \App\Support\Money::group($amount)
                . ' at ' . $branchName . '. Approve or cancel under L-BOX → Wallet Withdrawals.',
            url: '/admin/wallet-withdrawals',
            category: 'wallet',
        );
        $dealer = $withdrawal->branch?->distributorUser?->memberAccount;
        if ($dealer && $dealer->id !== $member->id) {
            \App\Services\Push\Notifier::to($dealer, 'wallet',
                'Withdrawal at your branch',
                $member->member_code . ' requested ₹' . \App\Support\Money::group($amount)
                    . '. Prepare cash/gold for the counter once HQ disburses.',
                route: '/wallet',
            );
        }

        return $withdrawal;
    }

    /**
     * "Withdraw My Wallet" (board spec 2026-08-09) — a dealer requests a withdrawal
     * from the admin panel, no L-BOX scan involved. Two pots:
     *  - member_cash: the dealer's own commission wallet (same debit as request()),
     *  - branch_digi: the branch Digi cash wallet (SRV credits) on branches.digi_cash_balance.
     * Either way the row lands in L-BOX → Wallet Withdrawals for HQ disbursal.
     */
    public function requestFromPanel(\App\Models\User $user, string $wallet, float $amount, ?string $note = null): WalletWithdrawal
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Enter an amount above zero.');
        }
        if (! in_array($wallet, ['member_cash', 'branch_digi'], true)) {
            throw new \InvalidArgumentException("Unknown wallet [{$wallet}].");
        }
        if (! $user->branch_id) {
            throw new \RuntimeException('Your login has no branch mapped — contact Head Office.');
        }

        $withdrawal = DB::transaction(function () use ($user, $wallet, $amount, $note) {
            $member = $user->memberAccount;

            if ($wallet === 'member_cash') {
                if (! $member) {
                    throw new \RuntimeException('Your login has no distributor account mapped — commission wallet unavailable.');
                }

                $memberWallet = MemberWallet::lockForUpdate()->find($member->id);
                if (! $memberWallet || (float) $memberWallet->cash_balance < $amount) {
                    throw new \InvalidArgumentException(sprintf(
                        'Insufficient wallet balance — available ₹%s.',
                        \App\Support\Money::group((float) ($memberWallet->cash_balance ?? 0)),
                    ));
                }
                $memberWallet->decrement('cash_balance', $amount);
                $memberWallet->increment('withdrawn_total', $amount);
            } else {
                $branch = \App\Models\Branch::lockForUpdate()->find($user->branch_id);
                if (! $branch || (float) $branch->digi_cash_balance < $amount) {
                    throw new \InvalidArgumentException(sprintf(
                        'Insufficient branch Digi cash — available ₹%s.',
                        \App\Support\Money::group((float) ($branch->digi_cash_balance ?? 0)),
                    ));
                }
                $branch->decrement('digi_cash_balance', $amount);
            }

            return WalletWithdrawal::create([
                'member_id' => $member?->id,
                'branch_id' => $user->branch_id,
                'wallet' => $wallet,
                'amount' => $amount,
                'status' => 'pending',
                'note' => $note,
            ]);
        });

        // HQ bell + admin mobile: a dealer asked for money from the panel.
        \App\Services\Push\Notifier::admins(
            'Withdrawal request — ' . ($withdrawal->branch?->name ?? 'branch #' . $withdrawal->branch_id),
            ($user->name ?: 'A dealer') . ' requested ₹' . \App\Support\Money::group($amount)
                . ' from the ' . ($wallet === 'branch_digi' ? 'branch Digi cash wallet' : 'commission wallet')
                . '. Approve or cancel under L-BOX → Wallet Withdrawals.',
            url: '/admin/wallet-withdrawals',
            category: 'wallet',
        );

        return $withdrawal;
    }

    /**
     * Branch incharge handed over gold/cash. A branch may disburse only once HQ has paid
     * the bundle (admin_paid); Head Office may also disburse a pending row directly.
     */
    public function disburse(WalletWithdrawal $withdrawal, string $mode, ?int $userId = null, ?string $note = null, bool $byHq = false): WalletWithdrawal
    {
        if ($withdrawal->status === 'admin_paid' || ($byHq && $withdrawal->status === 'pending')) {
            // allowed
        } elseif (in_array($withdrawal->status, ['pending', 'requested'], true)) {
            throw new \RuntimeException('Head Office has not paid this withdrawal to the branch yet — request the payout and wait for "Admin paid".');
        } else {
            throw new \RuntimeException('Only withdrawals paid by Head Office can be disbursed.');
        }
        if (! in_array($mode, ['cash', 'gold'], true)) {
            throw new \InvalidArgumentException("Disbursal mode must be cash or gold, [{$mode}] given.");
        }

        $withdrawal->update([
            'status' => 'disbursed',
            'disbursal_mode' => $mode,
            'disbursed_by' => $userId,
            'disbursed_at' => Carbon::now(),
            'note' => $note,
        ]);

        \App\Services\Push\Notifier::to($withdrawal->member, 'wallet',
            'Withdrawal disbursed',
            \App\Support\Money::inr((float) $withdrawal->amount) . ' handed over as ' . strtoupper($mode)
                . ' at ' . ($withdrawal->branch?->name ?? 'the branch') . '.',
            route: '/wallet',
        );

        return $withdrawal;
    }

    /**
     * Cancel an open withdrawal (pending / requested / admin_paid) — the wallet gets the
     * money back. Head Office only (board 2026-11-03); a branch can only request + disburse.
     * A row inside a payout bundle leaves it and the bundle's count/amount shrink.
     */
    public function cancel(WalletWithdrawal $withdrawal, ?int $userId = null, ?string $note = null): WalletWithdrawal
    {
        if (! in_array($withdrawal->status, WalletWithdrawal::OPEN, true)) {
            throw new \RuntimeException('Only open withdrawals (pending, requested or admin paid) can be cancelled.');
        }

        DB::transaction(function () use ($withdrawal, $userId, $note) {
            // Refund whichever pot the request debited (wallet column, 2026-08-09).
            if ($withdrawal->wallet === 'branch_digi') {
                \App\Models\Branch::where('id', $withdrawal->branch_id)
                    ->increment('digi_cash_balance', (float) $withdrawal->amount);
            } else {
                $wallet = MemberWallet::lockForUpdate()->find($withdrawal->member_id);
                $wallet?->increment('cash_balance', (float) $withdrawal->amount);
                $wallet?->decrement('withdrawn_total', (float) $withdrawal->amount);
            }

            $withdrawal->update([
                'status' => 'cancelled',
                'disbursed_by' => $userId,
                'note' => $note,
            ]);
            if ($withdrawal->payout_request_id) {
                $req = BranchPayoutRequest::lockForUpdate()->find($withdrawal->payout_request_id)?->recount();
                if ($req && $req->rows_count === 0 && $req->status === 'requested') {
                    $req->update(['status' => 'cancelled', 'cancelled_by' => $userId, 'cancelled_at' => Carbon::now(), 'note' => 'Every withdrawal in it was cancelled']);
                }
            }
        });

        \App\Services\Push\Notifier::to($withdrawal->member, 'wallet',
            'Withdrawal cancelled — amount refunded',
            \App\Support\Money::inr((float) $withdrawal->amount) . ' was returned to your '
                . ($withdrawal->wallet === 'branch_digi' ? 'branch Digi cash wallet' : 'commission wallet')
                . ($note ? '. Reason: ' . $note : '.'),
            route: '/wallet',
        );

        return $withdrawal;
    }

    /**
     * Board 2026-11-03 step 2 — the branch bundles its pending rows into ONE payout request
     * to Head Office. Rows move pending → requested; nothing is paid yet.
     *
     * @param  array<int>  $withdrawalIds
     */
    public function requestPayout(User $user, array $withdrawalIds, ?string $note = null): BranchPayoutRequest
    {
        if (! $user->branch_id) {
            throw new \RuntimeException('Your login has no branch mapped — contact Head Office.');
        }
        $ids = array_values(array_unique(array_map('intval', $withdrawalIds)));
        if (! $ids) {
            throw new \InvalidArgumentException('Select at least one pending withdrawal.');
        }

        $request = DB::transaction(function () use ($user, $ids, $note) {
            $rows = WalletWithdrawal::lockForUpdate()->whereIn('id', $ids)->get();
            if ($rows->count() !== count($ids)) {
                throw new \RuntimeException('Some selected withdrawals no longer exist.');
            }
            if ($rows->contains(fn (WalletWithdrawal $w) => (int) $w->branch_id !== (int) $user->branch_id)) {
                throw new \RuntimeException('Only your own branch\'s withdrawals can be requested.');
            }
            if ($rows->contains(fn (WalletWithdrawal $w) => $w->status !== 'pending')) {
                throw new \RuntimeException('Only pending withdrawals can be requested — some selected rows are already requested, paid or closed.');
            }

            $request = BranchPayoutRequest::create([
                'branch_id' => $user->branch_id,
                'requested_by' => $user->id,
                'rows_count' => $rows->count(),
                'amount' => round((float) $rows->sum('amount'), 2),
                'status' => 'requested',
                'note' => $note,
            ]);
            WalletWithdrawal::whereIn('id', $ids)->update(['status' => 'requested', 'payout_request_id' => $request->id]);

            return $request;
        });

        $branchName = $request->branch?->name ?? 'branch #' . $request->branch_id;
        \App\Services\Push\Notifier::admins(
            'Payout request — ' . $branchName,
            sprintf('%s asks Head Office to fund %d withdrawal(s), ₹%s. Pay or cancel under L-BOX → Branch Payout Requests.',
                $branchName, $request->rows_count, \App\Support\Money::group((float) $request->amount)),
            url: '/admin/branch-payout-requests',
            category: 'wallet',
        );

        return $request;
    }

    /**
     * Step 3 — Head Office transferred the bundle to the branch (bank, outside the system).
     * Records the reference; every row in it becomes admin_paid so the branch can disburse.
     */
    public function payRequest(BranchPayoutRequest $request, ?int $userId = null, ?string $paymentRef = null, ?string $note = null): BranchPayoutRequest
    {
        if ($request->status !== 'requested') {
            throw new \RuntimeException('Only a requested bundle can be paid.');
        }

        DB::transaction(function () use ($request, $userId, $paymentRef, $note) {
            $request->recount();
            if ($request->rows_count === 0) {
                throw new \RuntimeException('This bundle has no open withdrawals left to pay.');
            }
            $request->update([
                'status' => 'paid',
                'paid_by' => $userId,
                'paid_at' => Carbon::now(),
                'payment_ref' => $paymentRef,
                'note' => $note ?? $request->note,
            ]);
            $request->withdrawals()->where('status', 'requested')->update(['status' => 'admin_paid']);
        });

        $dealer = $request->dealerBank();
        if ($dealer) {
            \App\Services\Push\Notifier::to($dealer, 'wallet',
                'Head Office paid your payout request #' . $request->id,
                sprintf('₹%s for %d withdrawal(s) transferred%s. Hand over the cash at the counter and press Disburse on each.',
                    \App\Support\Money::group((float) $request->amount), $request->rows_count,
                    $paymentRef ? ' (ref ' . $paymentRef . ')' : ''),
                route: '/wallet',
            );
        }

        return $request;
    }

    /** HQ declines a bundle before paying it — its rows go back to pending (no money moves). */
    public function cancelRequest(BranchPayoutRequest $request, ?int $userId = null, ?string $note = null): BranchPayoutRequest
    {
        if ($request->status !== 'requested') {
            throw new \RuntimeException('Only a requested (unpaid) bundle can be cancelled.');
        }

        DB::transaction(function () use ($request, $userId, $note) {
            $request->withdrawals()->where('status', 'requested')->update(['status' => 'pending', 'payout_request_id' => null]);
            $request->update(['status' => 'cancelled', 'cancelled_by' => $userId, 'cancelled_at' => Carbon::now(), 'note' => $note]);
        });

        $dealer = $request->dealerBank();
        if ($dealer) {
            \App\Services\Push\Notifier::to($dealer, 'wallet',
                'Payout request #' . $request->id . ' cancelled by Head Office',
                'The withdrawals are back to pending' . ($note ? '. Reason: ' . $note : '.'),
                route: '/wallet',
            );
        }

        return $request;
    }

    /**
     * Undo a disbursal recorded before the HQ-funding rule (user 2026-11-03: 13 live rows
     * marked disbursed by the branches themselves). The row returns to pending — the member's
     * money is still out of the wallet and still owed, so no balance changes.
     */
    public function undoDisbursal(WalletWithdrawal $withdrawal, ?string $note = null): WalletWithdrawal
    {
        if ($withdrawal->status !== 'disbursed') {
            throw new \RuntimeException('Only a disbursed withdrawal can be undone.');
        }

        $withdrawal->update([
            'status' => 'pending',
            'disbursal_mode' => null,
            'disbursed_by' => null,
            'disbursed_at' => null,
            'note' => trim(($withdrawal->note ? $withdrawal->note . ' · ' : '') . ($note ?? 'disbursal undone')),
        ]);

        return $withdrawal;
    }
}
