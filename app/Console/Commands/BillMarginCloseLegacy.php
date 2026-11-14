<?php

namespace App\Console\Commands;

use App\Models\PendingBillMargin;
use App\Models\ResellerCommission;
use App\Services\Activity\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Lord's board 2026-11-14 — billing margin.
 *
 * The old system paid every billing margin up to 22-10-2026 (last payout run tbl_digi_waout
 * trid 386, 18–22 Oct, approved 23 Oct) but never marked the rows paid. So:
 *
 *   1. imported margin rows dated up to 22-10-2026  → marked PAID (they were, in the old system);
 *   2. the "Pending Billing Margin" balances (old accrued balance per branch) → CLOSED, nothing
 *      is credited — that money was already served;
 *   3. imported rows 23-10-2026 → 31-10-2026 stay unpaid: they are the only old-system margin
 *      still owed and are approved on Commission Approval with the normal TDS / service charge.
 *
 * Dry run by default; safe to run again.
 *
 *   php artisan billmargin:close-legacy            what would change
 *   php artisan billmargin:close-legacy --apply    write it
 */
class BillMarginCloseLegacy extends Command
{
    protected $signature = 'billmargin:close-legacy {--apply : write it (default = dry run)}';

    protected $description = 'Mark old-system billing margin up to 22-10-2026 as paid and close the Pending Billing Margin balances';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $upto = ResellerCommission::LEGACY_PAID_UPTO;
        $m = fn ($v) => \App\Support\Money::group((float) $v);

        $old = fn () => DB::table('reseller_commissions')->where('source', ResellerCommission::SOURCE_LEGACY)
            ->whereDate('bill_date', '<=', $upto)->where('status', '!=', 'paid');
        $owed = fn () => DB::table('reseller_commissions')->where('source', ResellerCommission::SOURCE_LEGACY)
            ->whereDate('bill_date', '>', $upto);
        $balances = fn () => DB::table('pending_bill_margins')->where('status', PendingBillMargin::STATUS_PENDING);
        $sent = DB::table('pending_bill_margins')->where('status', PendingBillMargin::STATUS_SENT);

        $oldN = $old()->count();
        $oldV = (float) $old()->sum('com_value');
        $balN = $balances()->count();
        $balV = (float) $balances()->sum('amount');

        $this->table(['What', 'Rows', 'Amount ₹', 'Action'], [
            ["Old-system margin rows up to {$upto}, not marked paid", $oldN, $m($oldV), 'mark PAID (paid in the old system)'],
            ['Pending Billing Margin balances still pending', $balN . ' branches', $m($balV), 'CLOSE — nothing credited'],
            ["Old-system margin rows after {$upto} — unpaid", (clone $owed())->where('status', '!=', 'paid')->count(),
                $m((clone $owed())->where('status', '!=', 'paid')->sum('com_value')), 'stay payable on Commission Approval ('
                . (clone $owed())->where('status', '!=', 'paid')->distinct()->count('branch_id') . ' branches)'],
            ["Old-system margin rows after {$upto} — already approved", (clone $owed())->where('status', 'paid')->count(),
                $m((clone $owed())->where('status', 'paid')->sum('com_value')), '—'],
        ]);

        // a balance Head Office had already SENT to a wallet from the old page = paid a second time
        if ((clone $sent)->exists()) {
            $this->warn(sprintf('ATTENTION: %d Pending Billing Margin balance(s), ₹%s, were already SENT to dealer wallets from the old page. '
                . 'The board says that money had been paid in the old system — these dealers may have received it twice. Not changed here:',
                (clone $sent)->count(), $m((clone $sent)->sum('amount'))));
            $this->table(['Branch', 'Amount ₹', 'Sent on'], DB::table('pending_bill_margins as p')->leftJoin('branches as b', 'b.id', '=', 'p.branch_id')
                ->where('p.status', PendingBillMargin::STATUS_SENT)->orderByDesc('p.amount')->limit(50)
                ->get(['b.name', 'p.amount', 'p.decided_at'])->map(fn ($r) => [$r->name, $m($r->amount), substr((string) $r->decided_at, 0, 10)])->all());
        }

        if (! $apply) {
            $this->comment('DRY RUN — nothing written. Add --apply to write it.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($old, $balances, $upto) {
            $old()->update([
                'status' => 'paid',
                'paid_on' => DB::raw('bill_date'),      // settled in the old system; its own date is the best record
                'updated_at' => now(),
            ]);
            $balances()->update([
                'status' => PendingBillMargin::STATUS_CLOSED,
                'remarks' => "Paid in the old system up to {$upto} (board 2026-11-14) — not payable",
                'decided_by' => auth()->id(),
                'decided_at' => now(),
                'updated_at' => now(),
            ]);
        });
        ActivityLogger::log('commission', 'legacy_bill_margin_closed',
            sprintf('Old-system billing margin up to %s marked paid (%d rows, ₹%s); %d Pending Billing Margin balances (₹%s) closed without credit — board 2026-11-14',
                $upto, $oldN, $m($oldV), $balN, $m($balV)),
            ['details' => ['rows' => $oldN, 'rows_value' => $oldV, 'balances' => $balN, 'balances_value' => $balV]]);

        $this->info(sprintf('APPLIED: %d row(s) ₹%s marked paid; %d balance(s) ₹%s closed. No wallet was touched.', $oldN, $m($oldV), $balN, $m($balV)));

        return self::SUCCESS;
    }
}
