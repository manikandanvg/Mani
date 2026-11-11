<?php

namespace App\Console\Commands;

use App\Models\Bond;
use App\Models\Branch;
use App\Models\Plan;
use App\Models\SalesInvoice;
use App\Services\AreaDistributorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Complaint 2026-11-11 (1): Area Distributor bills raised on the new system BEFORE the
 * branch + login were opened at billing got neither. This catches them up — same result as
 * a bill raised today: branch, distributor login (temporary password, pushed to the member's
 * app + printed here once) and the billed items as the branch's stock.
 *
 *   php artisan dealers:open-area-distributors                    # report only
 *   php artisan dealers:open-area-distributors --apply            # open them
 *   php artisan dealers:open-area-distributors --since=2026-11-01 # bills from this date (default: go-live)
 *
 * Idempotent: a member who already has a dealer login is skipped, so legacy Area Dealers
 * (imported with their login) are never touched.
 */
class OpenAreaDistributors extends Command
{
    protected $signature = 'dealers:open-area-distributors
        {--since=2026-11-01 : Only bills dated on / after this day}
        {--apply : Open the branches + logins (default: report only)}
        {--csv= : With --apply: also write the opened logins (with temporary passwords) to this CSV file}';

    protected $description = 'Open the branch + login for Area Distributor bills that were raised without one';

    public function handle(AreaDistributorService $svc): int
    {
        $planIds = Plan::all()->filter(fn (Plan $p) => $svc->appliesTo($p))->pluck('id');
        if ($planIds->isEmpty()) {
            $this->warn('No Area Distributor scheme (plans.hid = 8) found.');

            return self::SUCCESS;
        }

        $invoices = SalesInvoice::with(['customer', 'plan', 'lines'])
            ->whereIn('plan_id', $planIds)
            ->whereDate('date', '>=', $this->option('since'))
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'cancelled'))
            ->orderBy('id')->get();

        $rows = [];
        $opened = 0;
        foreach ($invoices as $invoice) {
            $member = $invoice->customer;
            if (! $member) {
                $rows[] = [$invoice->invoice_no, $invoice->date, '—', '—', 'skipped: no member on the bill', '', ''];
                continue;
            }
            if ($login = $svc->loginOf($member)) {
                $rows[] = [$invoice->invoice_no, $invoice->date, $member->member_code, $member->name, 'has login', $login->email, ''];
                continue;
            }
            if (! $this->option('apply')) {
                $rows[] = [$invoice->invoice_no, $invoice->date, $member->member_code, $member->name, 'WOULD OPEN branch + login', '', ''];
                $opened++;
                continue;
            }
            $res = DB::transaction(fn () => $svc->open($member, $invoice->plan, $invoice, $svc->piecesOfInvoice($invoice), null));
            if ($res && $res['created']) {
                $svc->sendLogin($member, $res);
                $rows[] = [$invoice->invoice_no, $invoice->date, $member->member_code, $member->name, 'OPENED branch #' . $res['branch_id'], $res['email'], $res['password']];
                $opened++;
            }
        }

        $this->table(['Invoice', 'Date', 'Member', 'Name', 'Result', 'Login', 'Temp password'], $rows);
        if ($this->option('apply') && ($path = $this->option('csv'))) {
            $fh = fopen($path, 'w');
            fwrite($fh, "\xEF\xBB\xBF");
            fputcsv($fh, ['Invoice', 'Date', 'Member code', 'Name', 'Phone', 'Result', 'Login', 'Temporary password', 'Sign in']);
            foreach ($rows as $r) {
                if ($r[6] !== '') {
                    $phone = \App\Models\Member::where('member_code', $r[2])->value('phone');
                    fputcsv($fh, [$r[0], $r[1], $r[2], $r[3], $phone, $r[4], $r[5], $r[6], rtrim((string) config('app.url'), '/') . '/admin/login']);
                }
            }
            fclose($fh);
            $this->warn('Logins written to ' . $path . ' — it holds passwords: hand them over, then delete the file.');
        }
        $this->info(sprintf('%d Area Distributor bill(s) since %s · %d %s.', $invoices->count(), $this->option('since'), $opened,
            $this->option('apply') ? 'opened (logins also pushed to each member\'s app inbox — note the passwords above, they are not shown again)' : 'would be opened — re-run with --apply'));

        return self::SUCCESS;
    }
}
