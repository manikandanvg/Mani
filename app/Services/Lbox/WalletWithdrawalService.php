<?php

namespace App\Services\Lbox;

use App\Models\Device;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\WalletWithdrawal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Closed-wallet withdrawal through the branch L-BOX static QR. The QR printed on the
 * box carries the device UUID; scanning it in the app targets that box's branch.
 * Requesting moves the balance member→branch immediately (and the box announces it);
 * the branch incharge then hands over gold/cash and marks the row disbursed.
 * Cancelling a pending row refunds the wallet. No payment gateway anywhere.
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

        // Branch-offline controls: a moved box or an unopened branch takes no money.
        if ($device->is_displaced) {
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
                    number_format((float) ($wallet->cash_balance ?? 0), 2),
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
                ? sprintf('ரூபாய் %s, %s அவர்களிடமிருந்து பெறப்பட்டது. கவுண்டரில் வழங்கவும்.', number_format($amount, 0), $member->name)
                : sprintf('Rupees %s received from %s. Please disburse at the counter.', number_format($amount, 0), $member->name);

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
            '₹' . number_format($amount, 2) . ' withdrawal requested at '
                . ($withdrawal->branch?->name ?? 'the branch') . '. Collect at the counter once disbursed.',
            route: '/wallet',
            data: ['withdrawal_id' => (string) $withdrawal->id],
        );

        // …and alert HQ admins + the branch's own dealer that cash is being asked for.
        $branchName = $withdrawal->branch?->name ?? 'branch #' . $withdrawal->branch_id;
        \App\Services\Push\Notifier::admins(
            'Withdrawal request — ' . $branchName,
            $member->member_code . ' requested ₹' . number_format($amount, 2)
                . ' at ' . $branchName . '. Approve or cancel under L-BOX → Wallet Withdrawals.',
            url: '/admin/wallet-withdrawals',
            category: 'wallet',
        );
        $dealer = $withdrawal->branch?->distributorUser?->memberAccount;
        if ($dealer && $dealer->id !== $member->id) {
            \App\Services\Push\Notifier::to($dealer, 'wallet',
                'Withdrawal at your branch',
                $member->member_code . ' requested ₹' . number_format($amount, 2)
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
                        number_format((float) ($memberWallet->cash_balance ?? 0), 2),
                    ));
                }
                $memberWallet->decrement('cash_balance', $amount);
                $memberWallet->increment('withdrawn_total', $amount);
            } else {
                $branch = \App\Models\Branch::lockForUpdate()->find($user->branch_id);
                if (! $branch || (float) $branch->digi_cash_balance < $amount) {
                    throw new \InvalidArgumentException(sprintf(
                        'Insufficient branch Digi cash — available ₹%s.',
                        number_format((float) ($branch->digi_cash_balance ?? 0), 2),
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
            ($user->name ?: 'A dealer') . ' requested ₹' . number_format($amount, 2)
                . ' from the ' . ($wallet === 'branch_digi' ? 'branch Digi cash wallet' : 'commission wallet')
                . '. Approve or cancel under L-BOX → Wallet Withdrawals.',
            url: '/admin/wallet-withdrawals',
            category: 'wallet',
        );

        return $withdrawal;
    }

    /** Branch incharge handed over gold/cash. */
    public function disburse(WalletWithdrawal $withdrawal, string $mode, ?int $userId = null, ?string $note = null): WalletWithdrawal
    {
        if ($withdrawal->status !== 'pending') {
            throw new \RuntimeException('Only pending withdrawals can be disbursed.');
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
            '₹' . number_format((float) $withdrawal->amount, 2) . ' handed over as ' . strtoupper($mode)
                . ' at ' . ($withdrawal->branch?->name ?? 'the branch') . '.',
            route: '/wallet',
        );

        return $withdrawal;
    }

    /** Cancel a pending withdrawal — the wallet gets the money back. */
    public function cancel(WalletWithdrawal $withdrawal, ?int $userId = null, ?string $note = null): WalletWithdrawal
    {
        if ($withdrawal->status !== 'pending') {
            throw new \RuntimeException('Only pending withdrawals can be cancelled.');
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
        });

        \App\Services\Push\Notifier::to($withdrawal->member, 'wallet',
            'Withdrawal cancelled — amount refunded',
            '₹' . number_format((float) $withdrawal->amount, 2) . ' was returned to your '
                . ($withdrawal->wallet === 'branch_digi' ? 'branch Digi cash wallet' : 'commission wallet')
                . ($note ? '. Reason: ' . $note : '.'),
            route: '/wallet',
        );

        return $withdrawal;
    }
}
