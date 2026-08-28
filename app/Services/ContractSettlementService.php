<?php

namespace App\Services;

use App\Models\ContractSettlement;
use App\Models\MemberContract;
use App\Models\MemberWallet;
use App\Services\Push\Notifier;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Generate settlement" on an EXPIRED contract (board phase 2, 2026-08-28).
 *
 * Head Office types the settlement value; it is credited to the distributor's cash
 * wallet immediately (no approval gate — the admin entering it IS the approval),
 * the contract closes, and the distributor gets a push + inbox line. The app shows
 * every settlement under My Earnings → Contract Settlement with the running wallet.
 */
class ContractSettlementService
{
    /** A contract is "expired" once its end date has passed or the engine parked it as matured. */
    public static function isExpired(MemberContract $contract, ?Carbon $today = null): bool
    {
        $today ??= Carbon::today();

        return $contract->status === 'matured'
            || ($contract->end_date !== null && $contract->end_date->lt($today));
    }

    public static function canGenerate(MemberContract $contract): bool
    {
        return self::isExpired($contract) && ! $contract->settlements()->exists();
    }

    public function generate(MemberContract $contract, float $amount, ?string $note, ?int $userId, ?Carbon $today = null): ContractSettlement
    {
        $today ??= Carbon::today();
        abort_if($amount <= 0, 422, 'Settlement value must be greater than zero.');

        return DB::transaction(function () use ($contract, $amount, $note, $userId, $today) {
            $contract = MemberContract::whereKey($contract->id)->lockForUpdate()->firstOrFail();
            abort_unless(self::isExpired($contract, $today), 422, 'This contract has not expired yet.');
            abort_if($contract->settlements()->exists(), 422, 'A settlement was already generated for this contract.');
            abort_unless($contract->member_id, 422, 'This contract has no distributor to credit.');

            $row = ContractSettlement::create([
                'member_contract_id' => $contract->id,
                'member_id' => $contract->member_id,
                'bond_id' => $contract->bond_id,
                'amount' => round($amount, 2),
                'note' => $note ?: null,
                'paid_on' => $today->toDateString(),
                'created_by' => $userId,
            ]);

            // Straight into the related wallet: cash (spendable / withdrawable) and the
            // gross earning counter, same buckets the commission approval uses.
            $wallet = MemberWallet::firstOrCreate(['member_id' => $contract->member_id]);
            $wallet->increment('cash_balance', $row->amount);
            $wallet->increment('earning_total', $row->amount);

            $contract->update([
                'status' => 'closed',
                'settled_on' => $contract->settled_on ?? $today->toDateString(),
            ]);
            $contract->bond?->update(['status' => 'closed']);

            DB::afterCommit(fn () => Notifier::to(
                $contract->member,
                'settlement',
                'Contract settled — ₹' . Money::group((float) $row->amount) . ' credited',
                'Your contract ' . $contract->contract_no . ' has been settled. ₹' . Money::group((float) $row->amount)
                    . ' was added to your wallet. See My Earnings → Contract Settlement.',
                route: '/business/earnings',
            ));

            return $row;
        });
    }
}
