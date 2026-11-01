<?php

namespace App\Console\Commands;

use App\Models\Bond;
use App\Models\MemberContract;
use App\Models\Plan;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Go-live 2026-11-01: every legacy contract lives in `bonds` (tbl_bond), but the Settlements
 * screen, "Generate settlement" and the app's Contract Settlement tab work on
 * `member_contracts`. This writes one member_contracts row per bond that has none, tagged
 * source = legacy (the automatic settlement engine skips those — HQ decides by hand):
 *
 *   - start = bond date, end = legacy return date when it is after the bond date, else
 *     bond date + the plan's validity (legacy stored return_date = bond_date on many rows)
 *   - bond closed/cancelled → contract closed (settled_on = return date)
 *   - bond active with end date passed → matured (shows under "Closed / expired contracts")
 *   - otherwise active
 *
 * Idempotent: bonds that already have a contract row are skipped. Contract no = LEG-<bond id>.
 */
class LegacyContractsBackfill extends Command
{
    protected $signature = 'contracts:backfill-legacy {--dry-run : count only, write nothing}';

    protected $description = 'Create member_contracts rows (source legacy) for every imported bond that has none';

    public function handle(): int
    {
        $today = Carbon::today();
        $dry = (bool) $this->option('dry-run');
        $validity = Plan::query()->pluck('validity_months', 'id')->map(fn ($v) => (int) $v)->all();

        $counts = ['active' => 0, 'matured' => 0, 'closed' => 0];
        $rows = [];
        $flush = function () use (&$rows, $dry) {
            if ($rows && ! $dry) {
                DB::table('member_contracts')->insert($rows);
            }
            $rows = [];
        };

        Bond::query()
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('member_contracts')->whereColumn('member_contracts.bond_id', 'bonds.id'))
            ->orderBy('id')
            ->chunkById(500, function ($bonds) use (&$rows, &$counts, $validity, $today, $flush) {
                foreach ($bonds as $b) {
                    $start = $b->bond_date ? Carbon::parse($b->bond_date)->startOfDay() : null;
                    $end = null;
                    if ($b->return_date && $start && Carbon::parse($b->return_date)->gt($start)) {
                        $end = Carbon::parse($b->return_date)->startOfDay();
                    } elseif ($start && ($validity[$b->plan_id] ?? 0) > 0) {
                        $end = $start->copy()->addMonthsNoOverflow($validity[$b->plan_id]);
                    }

                    if ($b->status !== 'active') {
                        $status = 'closed';
                        $settledOn = $b->return_date ? Carbon::parse($b->return_date)->toDateString() : null;
                    } elseif ($end && $end->lt($today)) {
                        $status = 'matured';
                        $settledOn = null;
                    } else {
                        $status = 'active';
                        $settledOn = null;
                    }
                    $counts[$status]++;

                    $rows[] = [
                        'contract_no' => 'LEG-' . $b->id,
                        'bond_id' => $b->id,
                        'member_id' => $b->member_id,
                        'plan_id' => $b->plan_id,
                        'branch_id' => $b->branch_id,
                        'invoice_no' => $b->invoice_no,
                        'amount' => $b->value,
                        'start_date' => $start?->toDateString(),
                        'end_date' => $end?->toDateString(),
                        'content' => null,
                        'status' => $status,
                        'source' => MemberContract::SOURCE_LEGACY,
                        'settled_on' => $settledOn,
                        'created_at' => $b->created_at ?? now(),
                        'updated_at' => now(),
                    ];
                }
                $flush();
            });
        $flush();

        $total = array_sum($counts);
        $this->info(($dry ? '[DRY RUN] would create ' : 'Created ') . number_format($total) . ' legacy contract rows: '
            . "{$counts['active']} active, {$counts['matured']} matured (expired, awaiting HQ decision), {$counts['closed']} closed.");

        return self::SUCCESS;
    }
}
