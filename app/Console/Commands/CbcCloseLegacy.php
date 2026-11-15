<?php

namespace App\Console\Commands;

use App\Services\Activity\ActivityLogger;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * User 2026-11-15 — the 1 October 2026 CBC coupons.
 *
 * The old system PAID the October CBC in full but never marked its rows (tbl_cbc stayed
 * PENDING, unlike August / September which it marked PASSED). They were imported as pending, so
 * the dashboard shows October as booked and never disbursed — and, worse, they can still be
 * approved on Commission Approval, which would pay them a second time.
 *
 * This marks them PAID the way the old system's own paid months are stored: status + paid date
 * only. NOTHING is credited to a wallet and no cash / TDS / retained split is written.
 *
 * Only the coupons the old system issued are touched — rows imported at go-live (created before
 * the cut-over). Coupons the NEW system created with an October date stay pending: the Sub
 * Dealer coupons scheduled on 2026-11-05 (cbc:schedule-missing) were never paid by anyone.
 *
 * Dealers: these rows carry no split, so `cbc:dealer-balance` (which pays the dealership balance
 * on coupons the new system approved) does not see them. If a dealer balance is owed for
 * October it needs its own decision — this command pays nobody.
 *
 *   php artisan cbc:close-legacy                         what would change (dry run)
 *   php artisan cbc:close-legacy --csv=oct-cbc.csv       … and the rows as a CSV
 *   php artisan cbc:close-legacy --paid-on=2026-10-05    the day the old system paid them
 *   php artisan cbc:close-legacy --apply                 write it
 */
class CbcCloseLegacy extends Command
{
    /** New-system go-live: anything created from this day on is the new system's own coupon. */
    public const CUT_OVER = '2026-11-01';

    protected $signature = 'cbc:close-legacy
        {--date=2026-10-01 : the coupon date to close}
        {--paid-on= : date the old system paid them (default: the coupon date, as for the earlier months)}
        {--csv= : write the rows to this CSV file (storage/app/reports)}
        {--apply : write it (default = dry run)}';

    protected $description = 'Mark the old-system CBC coupons of a month as paid (they were, in the old system) — credits nothing';

    public function handle(): int
    {
        $date = Carbon::parse((string) $this->option('date'))->toDateString();
        $paidOn = $this->option('paid-on') ? Carbon::parse((string) $this->option('paid-on'))->toDateString() : $date;
        $apply = (bool) $this->option('apply');
        $m = fn ($v) => Money::group((float) $v);

        if ($date >= self::CUT_OVER) {
            $this->error('Only a month the OLD system paid can be closed here (before ' . self::CUT_OVER . '). Coupons from then on are approved on Commission Approval.');

            return self::FAILURE;
        }

        $base = fn () => DB::table('cbc_entries as c')
            ->join('bonds as b', 'b.id', '=', 'c.bond_id')
            ->leftJoin('plans as p', 'p.id', '=', 'b.plan_id')
            ->whereDate('c.cbc_date', $date);
        // the old system's own coupons, still unmarked: imported at go-live, never approved here
        $legacy = fn () => $base()->where('c.status', '!=', 'paid')
            ->where('c.created_at', '<', self::CUT_OVER)
            ->whereNull('c.payout_run_id')
            ->where(fn ($w) => $w->whereNull('c.cash_amount')->orWhere('c.cash_amount', 0));
        $ours = fn () => $base()->where('c.status', '!=', 'paid')
            ->where(fn ($w) => $w->where('c.created_at', '>=', self::CUT_OVER)->orWhereNotNull('c.payout_run_id')->orWhere('c.cash_amount', '>', 0));

        $rows = [];
        foreach ($legacy()->selectRaw('p.code, COUNT(*) c, COUNT(DISTINCT c.member_id) members, SUM(c.worth) worth')->groupBy('p.code')->orderBy('p.code')->get() as $r) {
            $rows[] = [$r->code ?? '—', $r->c, $r->members, $m($r->worth), 'mark PAID on ' . $paidOn . ' — nothing credited'];
        }
        $n = $legacy()->count();
        $worth = (float) $legacy()->sum('c.worth');
        $rows[] = ['TOTAL', $n, $legacy()->distinct()->count('c.member_id'), $m($worth), ''];

        $this->info("CBC coupons dated {$date} — issued by the old system, not yet marked paid:");
        $this->table(['Scheme', 'Coupons', 'Distributors', 'Worth ₹', 'Action'], $rows);

        $keep = $ours()->selectRaw('p.code, COUNT(*) c, SUM(c.worth) worth')->groupBy('p.code')->get();
        if ($keep->isNotEmpty()) {
            $this->warn('Left PENDING — created by the new system, never paid by the old one (approve them on Commission Approval):');
            $this->table(['Scheme', 'Coupons', 'Worth ₹'], $keep->map(fn ($r) => [$r->code ?? '—', $r->c, $m($r->worth)])->all());
        }
        $already = $base()->where('c.status', 'paid');
        $this->line('Already paid for this date: ' . (clone $already)->count() . ' coupon(s), ₹' . $m((clone $already)->sum('c.worth')) . '.');
        $this->comment('Check the TOTAL against the old system:  SELECT COUNT(*), SUM(cbworth) FROM tbl_cbc WHERE cbcdate = \'' . $date . '\'');

        if ($csv = $this->option('csv')) {
            $path = storage_path('app/reports/' . basename((string) $csv));
            @mkdir(dirname($path), 0775, true);
            $fh = fopen($path, 'w');
            fputcsv($fh, ['coupon_id', 'code', 'cbc_date', 'member_code', 'member', 'scheme', 'bond_id', 'invoice_no', 'worth']);
            $legacy()->leftJoin('members as mb', 'mb.id', '=', 'c.member_id')->orderBy('c.id')
                ->select('c.id', 'c.code', 'c.cbc_date', 'mb.member_code', 'mb.name', 'p.code as plan', 'c.bond_id', 'b.invoice_no', 'c.worth')
                ->chunk(2000, function ($chunk) use ($fh) {
                    foreach ($chunk as $r) {
                        fputcsv($fh, [$r->id, $r->code, substr((string) $r->cbc_date, 0, 10), $r->member_code, $r->name, $r->plan, $r->bond_id, $r->invoice_no, $r->worth]);
                    }
                });
            fclose($fh);
            $this->line('CSV: ' . $path);
        }

        if (! $apply) {
            $this->comment('DRY RUN — nothing written. Add --apply to write it.');

            return self::SUCCESS;
        }
        if ($n === 0) {
            $this->info('Nothing to close.');

            return self::SUCCESS;
        }

        $done = DB::transaction(function () use ($legacy, $paidOn) {
            $ids = $legacy()->pluck('c.id');
            foreach ($ids->chunk(2000) as $chunk) {
                // status + date only: no wallet, no split — the money was paid by the old system
                DB::table('cbc_entries')->whereIn('id', $chunk)->where('status', '!=', 'paid')
                    ->update(['status' => 'paid', 'paid_on' => $paidOn, 'updated_at' => now()]);
            }

            return $ids->count();
        });

        ActivityLogger::log('commission', 'cbc_legacy_closed',
            "Old-system CBC coupons of {$date} marked paid: {$done} coupon(s), ₹" . $m($worth) . ' — paid in the old system, nothing credited', [
                'actor' => 'console cbc:close-legacy', 'channel' => 'console',
                'details' => ['cbc_date' => $date, 'paid_on' => $paidOn, 'coupons' => $done, 'worth' => $worth],
            ]);

        $this->info("Done: {$done} coupon(s), ₹" . $m($worth) . " marked PAID on {$paidOn}. No wallet was credited.");

        return self::SUCCESS;
    }
}
