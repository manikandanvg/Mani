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

        return DB::transaction(function () use ($member, $device, $amount) {
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

        return $withdrawal;
    }

    /** Cancel a pending withdrawal — the wallet gets the money back. */
    public function cancel(WalletWithdrawal $withdrawal, ?int $userId = null, ?string $note = null): WalletWithdrawal
    {
        if ($withdrawal->status !== 'pending') {
            throw new \RuntimeException('Only pending withdrawals can be cancelled.');
        }

        DB::transaction(function () use ($withdrawal, $userId, $note) {
            $wallet = MemberWallet::lockForUpdate()->find($withdrawal->member_id);
            $wallet?->increment('cash_balance', (float) $withdrawal->amount);
            $wallet?->decrement('withdrawn_total', (float) $withdrawal->amount);

            $withdrawal->update([
                'status' => 'cancelled',
                'disbursed_by' => $userId,
                'note' => $note,
            ]);
        });

        return $withdrawal;
    }
}
