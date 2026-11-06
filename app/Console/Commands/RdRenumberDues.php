<?php

namespace App\Console\Commands;

use App\Models\RdEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-06: renewals collected in the new system were numbered from due #1, but the
 * Gold Saving registration payment IS due #1 (legacy rdsales: rdcount + 2, every imported
 * row starts at #2). Walks each bond's renewals in order and RAISES any due number below
 * where it should be — registration #1, then +1 per due covered (a 3 × monthly payment on a
 * variable plan covers 3). Correct legacy rows are never lowered. Idempotent; dry run by default.
 */
class RdRenumberDues extends Command
{
    protected $signature = 'rd:renumber-dues {--apply : write the corrected due numbers}';

    protected $description = 'Renumber Gold Saving renewals so the registration payment is due #1 (dry run unless --apply)';

    public function handle(): int
    {
        $plans = DB::table('plans')->where('type', 'rd')->pluck('rd_qr_grams', 'id');
        $fixes = [];

        DB::table('bonds')->whereIn('plan_id', $plans->keys())->whereExists(fn ($q) => $q
            ->from('rd_entries')->whereColumn('rd_entries.bond_id', 'bonds.id'))
            ->select('id', 'plan_id', 'value')->orderBy('id')
            ->chunkById(500, function ($bonds) use ($plans, &$fixes) {
                $entries = RdEntry::whereIn('bond_id', $bonds->pluck('id'))
                    ->orderBy('paid_on')->orderBy('id')->get(['id', 'bond_id', 'value', 'due_count'])->groupBy('bond_id');

                foreach ($bonds as $bond) {
                    $fixedPlan = (float) ($plans[$bond->plan_id] ?? 0) > 0;
                    $monthly = (float) $bond->value;
                    $expected = 2;                       // registration = due #1
                    foreach ($entries[$bond->id] ?? [] as $e) {
                        $due = (int) $e->due_count;
                        if ($due < $expected) {
                            $fixes[] = [$e->id, $bond->id, $due, $expected];
                            $due = $expected;
                        }
                        $units = ($fixedPlan || $monthly <= 0) ? 1 : max(1, (int) round((float) $e->value / $monthly));
                        $expected = $due + $units;
                    }
                }
            });

        $this->info(count($fixes) . ' renewal(s) carry a due number below their place.');
        if ($fixes) {
            $this->table(['RD entry', 'Bond', 'Due # now', 'Due # correct'], array_slice($fixes, 0, 30));
            if (count($fixes) > 30) {
                $this->line('… ' . (count($fixes) - 30) . ' more.');
            }
        }

        if (! $this->option('apply')) {
            $this->comment('Dry run — re-run with --apply to write.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($fixes) {
            foreach ($fixes as [$id, , , $correct]) {
                RdEntry::whereKey($id)->update(['due_count' => $correct]);
            }
        });
        $this->info(count($fixes) . ' updated.');

        return self::SUCCESS;
    }
}
