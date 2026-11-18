<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User / Lord's board 2026-11-18: a G10 Gold Purchase or G10 Silver Purchase bill carries NO
 * contract — it is an outright purchase with a tax invoice and a redeemable QR. The new system
 * never issues one (plans.is_contract = 0), but the go-live back-fill wrote a contract row
 * (LEG-…) for every old-system bond, 3,066 of them G10 Gold: they showed up on the Settlements
 * screen as contracts to settle, which they are not.
 *
 * The rows are MOVED to member_contracts_removed (kept, with the reason) and then deleted from
 * member_contracts. A contract that has a settlement against it is never touched. Bonds, bills,
 * QRs and redemption invoices are not changed.
 */
return new class extends Migration
{
    private const SCHEMES = ['%G10 Gold Purchase%', '%G10 Silver Purchase%'];

    private const REASON = 'G10 purchase bill carries no contract (2026-11-18)';

    public function up(): void
    {
        if (! Schema::hasTable('member_contracts_removed')) {
            Schema::create('member_contracts_removed', function (Blueprint $t) {
                $t->unsignedBigInteger('id')->primary();          // the original member_contracts id
                $t->string('contract_no')->nullable();
                $t->unsignedBigInteger('bond_id')->nullable()->index();
                $t->unsignedBigInteger('member_id')->nullable()->index();
                $t->unsignedBigInteger('plan_id')->nullable();
                $t->unsignedBigInteger('branch_id')->nullable();
                $t->string('invoice_no')->nullable();
                $t->decimal('amount', 14, 2)->nullable();
                $t->date('start_date')->nullable();
                $t->date('end_date')->nullable();
                $t->longText('content')->nullable();
                $t->string('status')->nullable();
                $t->string('source')->nullable();
                $t->date('settled_on')->nullable();
                $t->timestamps();
                $t->timestamp('removed_at')->nullable();
                $t->string('removed_reason')->nullable();
            });
        }

        $planIds = $this->planIds();
        if (! $planIds) {
            return;
        }

        DB::transaction(function () use ($planIds) {
            $now = now();
            DB::table('member_contracts')->whereIn('plan_id', $planIds)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('contract_settlements')->whereColumn('contract_settlements.member_contract_id', 'member_contracts.id'))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('rd_settlements')->whereColumn('rd_settlements.member_contract_id', 'member_contracts.id'))
                ->orderBy('id')
                ->chunkById(500, function ($rows) use ($now) {
                    $copy = $rows->map(fn ($r) => [
                        'id' => $r->id, 'contract_no' => $r->contract_no, 'bond_id' => $r->bond_id, 'member_id' => $r->member_id,
                        'plan_id' => $r->plan_id, 'branch_id' => $r->branch_id, 'invoice_no' => $r->invoice_no, 'amount' => $r->amount,
                        'start_date' => $r->start_date, 'end_date' => $r->end_date, 'content' => $r->content, 'status' => $r->status,
                        'source' => $r->source, 'settled_on' => $r->settled_on, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
                        'removed_at' => $now, 'removed_reason' => self::REASON,
                    ])->all();
                    DB::table('member_contracts_removed')->insertOrIgnore($copy);
                    DB::table('member_contracts')->whereIn('id', $rows->pluck('id'))->delete();
                });
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('member_contracts_removed')) {
            return;
        }
        DB::transaction(function () {
            DB::table('member_contracts_removed')->where('removed_reason', self::REASON)->orderBy('id')
                ->chunkById(500, function ($rows) {
                    $back = $rows->map(fn ($r) => collect((array) $r)->except(['removed_at', 'removed_reason'])->all())->all();
                    DB::table('member_contracts')->insertOrIgnore($back);
                    DB::table('member_contracts_removed')->whereIn('id', $rows->pluck('id'))->delete();
                });
        });
    }

    private function planIds(): array
    {
        $ids = [];
        foreach (self::SCHEMES as $like) {
            $ids = array_merge($ids, DB::table('plans')->where('name', 'like', $like)->pluck('id')->all());
        }

        return array_values(array_unique($ids));
    }
};
