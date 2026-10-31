<?php

namespace App\Services\Payout;

use App\Models\Bond;
use App\Models\CbcEntry;
use App\Models\CommissionLedger;
use App\Models\Member;
use App\Models\PayoutRun;
use App\Services\Activity\ActivityLogger;
use App\Services\CommissionApprovalService;
use App\Services\CommissionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Monthly cut-off — legacy Ascript::index() reproduced on the new tables (docs/CUTOFF-PLAN.md).
 *
 *   Phase 1 GAP   every ACTIVE bond dated on/before the cut-off with instalments left pays the
 *                 FIVE RUPEE AMOUNTS STORED ON THE BOND (bonds.lvlcom, fixed at sale) to its five
 *                 uplines; level n is paid only when that upline's rank depth ≥ n. One pending
 *                 commission_ledger row per level per bond, dated the 1st of the pay month; the bond
 *                 consumes one instalment. No earning-potential cap, no task factor, no proration —
 *                 exactly legacy; the two switches exist for the board's later policy.
 *   Phase 2 log   per-member summary (rows, gross, TDS, service, net) = legacy tbl_gap_log → the
 *                 PayoutRun record + a text log like logs/ascript_payout_*.log.
 *   Phase 3 CBC   coupons due on the pay month's 1st: new-system bonds get theirs issued
 *                 (CommissionService::issueCbc), legacy ones were pre-scheduled; all are tagged to
 *                 the run. Nothing touches a wallet: Commission Approval (or "Approve run") credits
 *                 the CASH wallet net of TDS + service (board: no digi wallet).
 */
class CutoffService
{
    public const MAX_LEVELS = 5;

    /** @var callable|null */
    protected $logger = null;

    /** @var resource|null */
    protected $logHandle = null;

    public function __construct(
        protected CommissionService $commissions,
        protected CommissionApprovalService $approval,
    ) {}

    /** First day of the month AFTER the cut-off date (legacy `strtotime('first day of next month')`). */
    public static function periodFor(Carbon $cutoff): Carbon
    {
        return $cutoff->copy()->startOfMonth()->addMonthNoOverflow()->startOfDay();
    }

    /**
     * @param  array{ep_cap?:bool, task_factor?:bool, dry_run?:bool, plan_fallback?:bool}  $options
     */
    public function run(Carbon $cutoff, array $options = [], ?callable $echo = null, ?int $runBy = null): PayoutRun
    {
        $options += ['ep_cap' => false, 'task_factor' => false, 'dry_run' => false, 'plan_fallback' => true];
        $period = self::periodFor($cutoff);
        $this->logger = $echo;

        $existing = PayoutRun::whereDate('period', $period->toDateString())->where('status', '!=', 'dry_run')->first();
        if ($existing && ! $options['dry_run']) {
            // idempotent per bond anyway, but a second run of the same month only picks up bonds
            // the first one missed — say so loudly in the log.
            $this->say("NOTE: a run for {$period->format('M Y')} already exists (#{$existing->id}); only bonds without a GAP row this month are processed.");
        }

        $logPath = 'payouts/cutoff_' . $period->format('Y-m') . '_' . now()->format('Ymd_His') . ($options['dry_run'] ? '_dry' : '') . '.log';
        Storage::disk('local')->put($logPath, '');
        $this->logHandle = fopen(Storage::disk('local')->path($logPath), 'a');

        $run = new PayoutRun([
            'period' => $period->toDateString(),
            'cutoff_date' => $cutoff->toDateString(),
            'status' => $options['dry_run'] ? 'dry_run' : 'generated',
            'options' => ['ep_cap' => (bool) $options['ep_cap'], 'task_factor' => (bool) $options['task_factor'], 'plan_fallback' => (bool) $options['plan_fallback']],
            'log_path' => $logPath,
            'run_by' => $runBy,
            'started_at' => now(),
        ]);
        if (! $options['dry_run']) {
            $run->save();
        }

        $this->say(($options['dry_run'] ? 'DRY RUN — ' : '') . 'MONTHLY CUT-OFF  cut-off date ' . $cutoff->toDateString() . '  pay month ' . $period->format('M Y') . ' (rows dated ' . $period->toDateString() . ')');
        $this->say('options: ' . json_encode($run->options));
        $this->say('');

        // ---------------------------------------------------------------- phase 1: GAP
        $ranks = DB::table('ranks')->pluck('depth', 'id')->map(fn ($d) => (int) $d)->all();
        $members = Member::query()->select('id', 'member_code', 'name', 'upline_id', 'rank_id', 'status', 'branch_id')->get()->keyBy('id');

        $stats = ['bonds' => 0, 'rows' => 0, 'gross' => 0.0, 'members' => [], 'skipped_done' => 0, 'skipped_zero' => 0];
        $batch = [];
        $consumed = [];

        $alreadyThisMonth = CommissionLedger::where('type', 'GAP')
            ->whereBetween('earned_on', [$period->toDateString(), $period->copy()->endOfMonth()->toDateString()])
            ->pluck('bond_id')->flip()->all();

        $query = Bond::query()->with('plan:id,level_schedule')
            ->where('status', 'active')
            ->whereColumn('lvlcom_issued', '<', 'lvlcom_count')
            ->whereDate('bond_date', '<=', $cutoff->toDateString())
            ->orderByDesc('bond_date')->orderByDesc('id');
        $this->say('BOND ROWS TO PROCESS : ' . $query->count());

        foreach ($query->cursor() as $bond) {
            if (isset($alreadyThisMonth[$bond->id])) {
                $stats['skipped_done']++;
                continue;
            }
            $amounts = $this->levelAmounts($bond, (bool) $options['plan_fallback']);
            if (array_sum($amounts) <= 0) {
                $stats['skipped_zero']++;
                continue;
            }
            $owner = $members[$bond->member_id] ?? null;
            if (! $owner) {
                $stats['skipped_zero']++;
                continue;
            }
            $this->say('');
            $this->say('USERNAME : ' . $owner->name . ' USERID : ' . $owner->member_code . ' BOND:' . $bond->id . ' instalment ' . ((int) $bond->lvlcom_issued + 1) . '/' . (int) $bond->lvlcom_count);
            $this->say('-------------------------------------------------------------------------');

            // level 1 = the upline FROZEN ON THE CONTRACT at sale (legacy tbl_bond.uplineuserid); a later
            // re-mapping of the distributor does not move the contract's income. Levels 2–5 walk up from
            // there through the current network. No frozen upline → the member's current upline.
            $upline = $bond->upline_member_id ? ($members[$bond->upline_member_id] ?? null) : null;
            $upline ??= $owner->upline_id ? ($members[$owner->upline_id] ?? null) : null;
            $level = 0;
            while ($upline && $level < self::MAX_LEVELS) {
                $level++;
                // legacy's register column is an INTEGER: every level amount lands as whole rupees
                // (MySQL rounds half away from zero, like PHP round()) — 2500.5 → 2501.
                $amount = round((float) ($amounts[$level - 1] ?? 0));
                $depth = $ranks[$upline->rank_id] ?? 0;
                $this->say('Checking  ' . $upline->member_code . ' DEPTH : ' . $depth . ' for level ' . $level);
                if ($amount > 0 && $depth >= $level) {
                    if ($options['task_factor']) {
                        $amount = round($amount * \App\Models\TaskScore::factorFor($upline->id, $period->copy()->subMonthNoOverflow()), 2);
                    }
                    if ($options['ep_cap']) {
                        $amount = $this->commissions->capByEp($members[$upline->id], $amount, $period->toDateString());
                    }
                    if ($amount > 0) {
                        $batch[] = [
                            'type' => 'GAP', 'member_id' => $upline->id, 'from_member_id' => $bond->member_id, 'bond_id' => $bond->id,
                            'invoice_no' => $bond->invoice_no, 'level' => $level, 'amount' => $amount, 'status' => 'pending', 'pay_via' => null,
                            'earned_on' => $period->toDateString(), 'paid_on' => null, 'branch_id' => $bond->branch_id,
                            'payout_run_id' => $run->id, 'tds' => 0, 'service_charge' => 0, 'net_amount' => $amount,
                            'currency_code' => 'INR', 'fx_rate' => 1.0, 'created_at' => now(), 'updated_at' => now(),
                        ];
                        $stats['rows']++;
                        $stats['gross'] += $amount;
                        $stats['members'][$upline->id] = true;
                        $this->say('GAP  ' . $upline->member_code . ' EARN Rs.' . number_format($amount, 2, '.', '') . ' FROM : ' . $owner->name . ' (' . $owner->member_code . ')');
                    }
                }
                $upline = $upline->upline_id ? ($members[$upline->upline_id] ?? null) : null;
            }
            // the instalment is consumed whether or not anyone qualified (legacy's effective behaviour)
            $consumed[] = $bond->id;
            $stats['bonds']++;

            if (! $options['dry_run'] && count($batch) >= 1000) {
                $this->flush($batch, $consumed);
            }
        }
        if (! $options['dry_run']) {
            $this->flush($batch, $consumed);
        }
        $this->say('');
        $this->say(sprintf('PHASE 1 DONE : %d earning rows, %d bonds issued, gross Rs.%s (skipped: %d already run this month, %d with no level amounts)',
            $stats['rows'], $stats['bonds'], number_format($stats['gross'], 2, '.', ''), $stats['skipped_done'], $stats['skipped_zero']));

        // ---------------------------------------------------------------- phase 2: per-member log
        $run->fill([
            'gap_bonds' => $stats['bonds'], 'gap_rows' => $stats['rows'], 'gap_members' => count($stats['members']),
            'gap_gross' => round($stats['gross'], 2),
        ]);
        if (! $options['dry_run']) {
            $run->save();
            foreach ($run->memberSummary() as $s) {
                $this->say(sprintf('GAPLOG %s COUNT:%d NET:%s TDS:%s SC:%s PAYABLE:%s', $s['member_code'], $s['rows'],
                    number_format($s['gross'], 2, '.', ''), number_format($s['tds'], 2, '.', ''), number_format($s['service'], 2, '.', ''), number_format($s['net'], 2, '.', '')));
            }
        }
        $this->say('PHASE 2 DONE : ' . count($stats['members']) . ' members in the GAP log (pending — Commission Approval credits the cash wallet)');

        // ---------------------------------------------------------------- phase 3: CBC
        $issued = 0;
        if (! $options['dry_run']) {
            $issued = $this->commissions->issueCbc($period);   // new-system bonds; legacy coupons are pre-scheduled
        }
        $due = CbcEntry::query()
            ->whereBetween('cbc_date', [$period->toDateString(), $period->copy()->endOfMonth()->toDateString()]);
        $cbcRows = (int) (clone $due)->count();
        $cbcWorth = round((float) (clone $due)->sum('worth'), 2);
        if (! $options['dry_run']) {
            (clone $due)->whereNull('payout_run_id')->update(['payout_run_id' => $run->id]);
        }
        $this->say(sprintf('PHASE 3 DONE : CBC coupons due %s : %d (worth Rs.%s), %d newly issued for new-system bonds',
            $period->format('M Y'), $cbcRows, number_format($cbcWorth, 2, '.', ''), $issued));

        $run->fill(['cbc_rows' => $cbcRows, 'cbc_worth' => $cbcWorth, 'finished_at' => now()]);
        if (! $options['dry_run']) {
            $run->save();
            ActivityLogger::log('commission', 'cutoff_run', 'Monthly cut-off ' . $period->format('M Y') . ': ' . $stats['rows'] . ' GAP rows for ' . count($stats['members']) . ' members, gross ₹' . number_format($stats['gross'], 2) . '; ' . $cbcRows . ' CBC coupons due', [
                'subject' => $run, 'details' => ['run_id' => $run->id, 'cutoff' => $cutoff->toDateString(), 'options' => $run->options, 'log' => $logPath],
            ]);
        }
        $this->say('');
        $this->say('TOTAL TIME : ' . $run->started_at->diffInSeconds(now()) . ' sec');
        if ($this->logHandle) {
            fclose($this->logHandle);
            $this->logHandle = null;
        }

        return $run;
    }

    /**
     * Approve every pending row of the run (GAP ledger + CBC coupons): cash wallet net of
     * TDS + service for GAP, 40 % EPIN + 60 % coupon for CBC — through the same
     * CommissionApprovalService the manual page uses, so the rules are identical.
     *
     * @return array{gap:int, cbc:int, members:int, gross:float}
     */
    public function approve(PayoutRun $run, ?int $by = null): array
    {
        $out = ['gap' => 0, 'cbc' => 0, 'members' => 0, 'gross' => 0.0];
        $perMember = [];
        foreach ($run->gapRows()->where('status', '!=', 'paid')->with('member')->cursor() as $row) {
            if ($this->approval->approve($row)) {
                $out['gap']++;
                $out['gross'] += (float) $row->amount;
                $perMember[$row->member_id] = ($perMember[$row->member_id] ?? 0) + (float) $row->amount;
            }
        }
        foreach ($run->cbcRows()->where('status', 'pending')->cursor() as $row) {
            if ($this->approval->approve($row)) {
                $out['cbc']++;
            }
        }
        $out['members'] = count($perMember);
        $run->update(['status' => 'approved', 'approved_by' => $by, 'approved_at' => now()]);
        foreach ($perMember as $memberId => $gross) {
            \App\Services\Push\Notifier::to(Member::find($memberId), 'commission', 'Turnover salary credited',
                'Your ' . $run->label() . ' turnover-based salary (gross ₹' . \App\Support\Money::group($gross) . ') was approved; the net after TDS & service charge is in your wallet.',
                route: '/wallet');
        }
        ActivityLogger::log('commission', 'cutoff_approved', 'Cut-off ' . $run->label() . ' approved: ' . $out['gap'] . ' GAP rows (gross ₹' . number_format($out['gross'], 2) . ') for ' . $out['members'] . ' members, ' . $out['cbc'] . ' CBC coupons', [
            'subject' => $run, 'details' => $out,
        ]);

        return $out;
    }

    /**
     * The five rupee amounts a bond pays per instalment: the stored lvlcom array (legacy import
     * and the Sales screen both fill it). All-zero stored amounts mean "this contract pays no
     * level income" (legacy `lvlcomone > 0` filter) — only a bond WITHOUT the array at all falls
     * back to plan % × value.
     */
    public function levelAmounts(Bond $bond, bool $planFallback = true): array
    {
        $raw = $bond->lvlcom;
        if (is_array($raw) && count($raw) > 0) {
            $stored = array_values(array_map('floatval', $raw));

            return array_pad(array_slice($stored, 0, self::MAX_LEVELS), self::MAX_LEVELS, 0.0);
        }
        if (! $planFallback) {
            return array_fill(0, self::MAX_LEVELS, 0.0);
        }
        $schedule = (array) ($bond->plan?->level_schedule ?? []);
        $out = [];
        for ($i = 0; $i < self::MAX_LEVELS; $i++) {
            $out[] = round((float) $bond->value * (float) ($schedule[$i] ?? 0) / 100, 2);
        }

        return $out;
    }

    protected function flush(array &$batch, array &$consumed): void
    {
        DB::transaction(function () use (&$batch, &$consumed) {
            foreach (array_chunk($batch, 500) as $chunk) {
                DB::table('commission_ledger')->insert($chunk);
            }
            foreach (array_chunk($consumed, 1000) as $chunk) {
                DB::table('bonds')->whereIn('id', $chunk)->increment('lvlcom_issued');
            }
        });
        $batch = [];
        $consumed = [];
    }

    protected function say(string $line): void
    {
        if ($this->logHandle) {
            fwrite($this->logHandle, $line . "\n");
        }
        if ($this->logger) {
            ($this->logger)($line);
        }
    }
}
