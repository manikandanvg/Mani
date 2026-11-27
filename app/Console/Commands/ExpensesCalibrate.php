<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Models\Rank;
use App\Support\Money;
use App\Support\Translatable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Board 2026-11-27: "calibrate all expenses into the expense ledger, from our first data date".
 *
 * Writes the standing expenses the board listed as rows of the expense register, month by month
 * from the first bill on record, plus the capital assets once on that first date:
 *
 *   TBP award        one row per distributor per stage reached (Rank Changes register), at the
 *                    stage's award value on the TBP Stages page
 *   Meetings         weekly ₹25,000 (one row a month = Saturdays of the month × 25,000), monthly ₹5,00,000
 *   Office rent      corporate office ₹12,000 a month
 *   Food             office food & beverages ₹10,000 a month
 *   Travel           branch visits / transit of material ₹30,000 a month
 *   Fuel             ₹3,000 a working day for the 5 cars (one row a month = days without the
 *                    Sundays × 3,000 — Sunday is a holiday, board 27-11-2026)
 *   Capital assets   computers 5,00,000 · interior 30,000 · factory machinery & tools 50,00,000 ·
 *                    cars (5 nos) 60,00,000
 *
 * Every row carries source = calibration and a source_key, so the command can be run again (a new
 * month, a new rank) without doubling anything. Dry run by default; rows are written as SUBMITTED
 * (the owner approves them on Report → Expenses) unless --approve is given. Only approved rows
 * reach the auditor's reports.
 */
class ExpensesCalibrate extends Command
{
    protected $signature = 'expenses:calibrate
        {--from= : first date (default = the first bill on record)}
        {--to= : last date (default = today)}
        {--only= : comma list of tbp,meeting,rent,food,travel,fuel,assets (default = all)}
        {--fuel-per-car : read the fuel figure as 3,000 a day for EACH of the 5 cars (default = for the 5 together)}
        {--approve : write the rows as approved (default = submitted, waiting for the owner)}
        {--undo : remove the calibrated rows instead of writing them}
        {--apply : write (default = dry run)}';

    protected $description = 'Write the board\'s standing expenses and capital assets into the expense ledger from the first data date (dry run by default)';

    public const WEEKLY_MEETING = 25000;
    public const MONTHLY_MEETING = 500000;
    public const OFFICE_RENT = 12000;
    public const FOOD = 10000;
    public const TRAVEL = 30000;
    public const FUEL_PER_DAY = 3000;
    public const CARS = 5;

    /** category => [title, amount] — bought once, dated the first data date. */
    public const ASSETS = [
        'asset_computers' => ['Computers & Peripherals', 500000],
        'asset_interior' => ['Interior cost', 30000],
        'asset_machinery' => ['Factory machinery & tools', 5000000],
        'asset_vehicles' => ['Cars — 5 nos', 6000000],
    ];

    public const NOTE = 'Calibrated from the board\'s figures of 27-11-2026 — no bill attached.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if ($this->option('undo')) {
            return $this->undo($apply);
        }

        $first = DB::table('bonds')->min('bond_date');
        $from = Carbon::parse($this->option('from') ?: $first ?: now())->startOfDay();
        $to = Carbon::parse($this->option('to') ?: now())->startOfDay();
        if ($from->gt($to)) {
            $this->error('--from is after --to.');

            return self::FAILURE;
        }
        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        $want = fn (string $k) => $only === [] || in_array($k, $only, true);

        $rows = [];
        if ($want('assets')) {
            foreach (self::ASSETS as $category => [$title, $amount]) {
                $rows[] = $this->row('asset:' . $category, $from, $category, $title, $amount);
            }
        }
        foreach ($this->months($from, $to) as [$start, $end]) {
            $ym = $start->format('Y-m');
            $label = $start->format('M Y');
            $date = $end;   // booked on the last day of the month (today for the running month)
            if ($want('rent')) {
                $rows[] = $this->row("rent:$ym", $date, 'office_rent', "Corporate office rent — $label", self::OFFICE_RENT, ['period_month' => $ym]);
            }
            if ($want('food')) {
                $rows[] = $this->row("food:$ym", $date, 'food', "Office food & beverages — $label", self::FOOD, ['period_month' => $ym]);
            }
            if ($want('meeting')) {
                $rows[] = $this->row("meeting-monthly:$ym", $date, 'meeting', "Monthly meeting — $label", self::MONTHLY_MEETING, ['period_month' => $ym]);
                $weeks = $this->countDays($start, $end, fn (Carbon $d) => $d->isSaturday());   // Sunday is a holiday
                if ($weeks > 0) {
                    $rows[] = $this->row("meeting-weekly:$ym", $date, 'meeting',
                        "Weekly meetings — $label ($weeks × ₹" . Money::group(self::WEEKLY_MEETING) . ')', $weeks * self::WEEKLY_MEETING, ['period_month' => $ym]);
                }
            }
            if ($want('travel')) {
                $rows[] = $this->row("travel:$ym", $date, 'travel', "Branch visits / transit of material (gold, silver) — $label", self::TRAVEL, ['period_month' => $ym]);
            }
            if ($want('fuel')) {
                $days = $this->countDays($start, $end, fn (Carbon $d) => ! $d->isSunday());   // Sunday is a holiday
                $perDay = self::FUEL_PER_DAY * ($this->option('fuel-per-car') ? self::CARS : 1);
                $rows[] = $this->row("fuel:$ym", $date, 'fuel',
                    'Fuel — ' . self::CARS . " cars — $label ($days working days × ₹" . Money::group($perDay) . ')', $days * $perDay, ['period_month' => $ym]);
            }
        }
        if ($want('tbp')) {
            array_push($rows, ...$this->tbpRows($from, $to));
        }

        $have = DB::table('expenses')->whereNotNull('source_key')->get(['id', 'source_key', 'source', 'amount', 'status'])->keyBy('source_key');
        $new = array_values(array_filter($rows, fn (array $r) => ! isset($have[$r['source_key']])));
        // a calibrated row whose figure changed (a rule changed since it was written): corrected
        // while it still waits for approval, only reported once it is approved
        $changed = $locked = [];
        foreach ($rows as $r) {
            $old = $have[$r['source_key']] ?? null;
            if ($old && $old->source === 'calibration' && abs((float) $old->amount - $r['amount']) > 0.005) {
                $old->status === Expense::APPROVED ? $locked[] = $r : $changed[$old->id] = $r;
            }
        }

        $this->info(sprintf('Expense ledger %s → %s — %d row(s) worked out, %d already in the ledger, %d new, %d to correct.',
            $from->format('d-m-Y'), $to->format('d-m-Y'), count($rows), count($rows) - count($new), count($new), count($changed)));
        $this->summary($new);
        $this->csv($new);
        if ($changed !== []) {
            $was = (float) collect($have)->whereIn('id', array_keys($changed))->sum('amount');
            $this->line(count($changed) . ' waiting row(s) carry an old figure: ' . Money::inr($was) . ' → ' . Money::inr((float) array_sum(array_column($changed, 'amount'))));
        }
        if ($locked !== []) {
            $this->warn(count($locked) . ' APPROVED row(s) carry an old figure and are left alone — undo their approval on Report → Expenses, then run again: '
                . implode(', ', array_slice(array_column($locked, 'source_key'), 0, 8)) . (count($locked) > 8 ? ' …' : ''));
        }

        if (! $apply) {
            $this->warn('DRY RUN — nothing was written. Add --apply to write the new rows'
                . ($this->option('approve') ? ' as APPROVED.' : ' as SUBMITTED (the owner approves them on Report → Expenses); add --approve to write them approved.'));

            return self::SUCCESS;
        }

        $this->write($new, (bool) $this->option('approve'));
        foreach ($changed as $id => $r) {
            DB::table('expenses')->where('id', $id)->update(['amount' => $r['amount'], 'title' => $r['title'], 'updated_at' => now()]);
        }
        if ($changed !== []) {
            $this->info(count($changed) . ' waiting row(s) corrected.');
        }
        $this->info(count($new) . ' row(s) written' . ($this->option('approve') ? ', approved.' : ', waiting for approval.'));

        return self::SUCCESS;
    }

    /** One ledger row. */
    protected function row(string $key, Carbon $date, string $category, string $title, float $amount, array $extra = []): array
    {
        return $extra + [
            'source_key' => $key,
            'expense_date' => $date->toDateString(),
            'category' => $category,
            'title' => mb_substr($title, 0, 200),
            'amount' => round($amount, 2),
            'period_month' => null,
            'member_id' => null,
            'rank_id' => null,
            'payee_name' => null,
        ];
    }

    /**
     * The months of the window, each cut to the window: [first day, last day].
     *
     * @return array<int, array{0: Carbon, 1: Carbon}>
     */
    protected function months(Carbon $from, Carbon $to): array
    {
        $out = [];
        for ($m = $from->copy()->startOfMonth(); $m->lte($to); $m->addMonth()) {
            $start = $m->copy()->max($from);
            $end = $m->copy()->endOfMonth()->startOfDay()->min($to);
            $out[] = [$start, $end];
        }

        return $out;
    }

    protected function countDays(Carbon $start, Carbon $end, \Closure $counts): int
    {
        $n = 0;
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $n += $counts($d) ? 1 : 0;
        }

        return $n;
    }

    /** One award per distributor per stage, on the day the stage was first reached. */
    protected function tbpRows(Carbon $from, Carbon $to): array
    {
        $ranks = Rank::where('depth', '>', 0)->get()->keyBy('id');
        $rows = [];
        $noValue = [];
        $first = DB::table('rank_changes')->where('direction', 'up')
            ->selectRaw('member_id, to_rank_id, MIN(changed_at) AS reached')
            ->groupBy('member_id', 'to_rank_id')->orderBy('reached')->get();
        $members = DB::table('members')->whereIn('id', $first->pluck('member_id')->unique())->get(['id', 'name', 'member_code'])->keyBy('id');

        foreach ($first as $c) {
            $rank = $ranks[$c->to_rank_id] ?? null;
            $day = Carbon::parse($c->reached)->startOfDay();
            if (! $rank || $day->lt($from) || $day->gt($to)) {
                continue;
            }
            $stage = Translatable::pick($rank->name) ?: $rank->code;
            if ((float) $rank->reward_amount <= 0) {
                $noValue[$stage] = ($noValue[$stage] ?? 0) + 1;

                continue;
            }
            $m = $members[$c->member_id] ?? null;
            $rows[] = $this->row("tbp:{$c->member_id}:{$rank->id}", $day, 'tbp_reward',
                trim((Translatable::pick($rank->reward_title) ?: 'TBP award') . ' — ' . $stage . ($m ? ' — ' . $m->name . ' (' . $m->member_code . ')' : '')),
                (float) $rank->reward_amount,
                ['member_id' => $m?->id, 'rank_id' => $rank->id, 'payee_name' => $m?->name]);
        }
        foreach ($noValue as $stage => $n) {
            $this->warn("TBP: $n distributor(s) reached $stage — no award value is set on the TBP Stages page, no row written.");
        }

        return $rows;
    }

    protected function summary(array $rows): void
    {
        $by = [];
        foreach ($rows as $r) {
            $k = $r['category'] . ($r['rank_id'] ? ':' . $r['rank_id'] : '');
            $by[$k] ??= ['label' => Expense::categoryLabel($r['category'])
                . ($r['rank_id'] ? ' — ' . (Translatable::pick(Rank::find($r['rank_id'])?->name) ?: '') : ''), 'n' => 0, 'sum' => 0.0, 'asset' => isset(Expense::ASSET_CATEGORIES[$r['category']])];
            $by[$k]['n']++;
            $by[$k]['sum'] += $r['amount'];
        }
        $table = [];
        $expense = $asset = 0.0;
        foreach ($by as $b) {
            $table[] = [$b['asset'] ? 'Capital asset' : 'Expense', $b['label'], $b['n'], Money::inr($b['sum'])];
            $b['asset'] ? $asset += $b['sum'] : $expense += $b['sum'];
        }
        $this->table(['Kind', 'Ledger', 'Rows', 'Amount'], $table);
        $this->line('New expenses: ' . Money::inr($expense) . ' · new capital assets: ' . Money::inr($asset));
    }

    protected function csv(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $dir = storage_path('app/reports');
        @mkdir($dir, 0775, true);
        $path = $dir . '/expenses-calibrate-' . now()->format('Ymd-His') . '.csv';
        $fh = fopen($path, 'w');
        fputcsv($fh, ['Date', 'Category', 'Paid for', 'Amount', 'For month', 'Key']);
        foreach ($rows as $r) {
            fputcsv($fh, [$r['expense_date'], Expense::categoryLabel($r['category']), $r['title'], $r['amount'], $r['period_month'], $r['source_key']]);
        }
        fclose($fh);
        $this->line('Row list: ' . $path);
    }

    protected function write(array $rows, bool $approve): void
    {
        usort($rows, fn ($a, $b) => [$a['expense_date'], $a['source_key']] <=> [$b['expense_date'], $b['source_key']]);
        $owner = $approve ? DB::table('users')->orderBy('id')->value('id') : null;
        $now = now();

        DB::transaction(function () use ($rows, $approve, $owner, $now) {
            $n = (int) DB::table('expenses')->selectRaw("MAX(CAST(SUBSTRING(expense_no, 5) AS UNSIGNED)) AS n")->value('n');
            $n = max($n, (int) DB::table('expenses')->max('id'));
            foreach (array_chunk($rows, 500) as $chunk) {
                $insert = [];
                foreach ($chunk as $r) {
                    $insert[] = $r + [
                        'expense_no' => 'EXP-' . str_pad((string) ++$n, 6, '0', STR_PAD_LEFT),
                        'kind' => isset(Expense::ASSET_CATEGORIES[$r['category']]) ? Expense::KIND_ASSET : Expense::KIND_EXPENSE,
                        'source' => 'calibration',
                        'payment_mode' => 'bank',
                        'remarks' => self::NOTE,
                        'status' => $approve ? Expense::APPROVED : Expense::SUBMITTED,
                        'approved_by' => $owner,
                        'approved_at' => $approve ? $now : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                DB::table('expenses')->insert($insert);
            }
        });
    }

    protected function undo(bool $apply): int
    {
        $q = DB::table('expenses')->where('source', 'calibration');
        $this->table(['Status', 'Rows', 'Amount'], (clone $q)->selectRaw('status, COUNT(*) AS n, SUM(amount) AS total')->groupBy('status')->get()
            ->map(fn ($r) => [$r->status, $r->n, Money::inr((float) $r->total)])->all());
        if (! $apply) {
            $this->warn('DRY RUN — add --apply to remove these calibrated rows (rows typed by hand are never touched).');

            return self::SUCCESS;
        }
        $this->info($q->delete() . ' calibrated row(s) removed.');

        return self::SUCCESS;
    }
}
