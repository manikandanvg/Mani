<?php

namespace App\Console\Commands;

use App\Models\Bond;
use App\Models\CbcEntry;
use App\Models\Member;
use App\Models\Plan;
use App\Services\Activity\ActivityLogger;
use App\Services\CommissionApprovalService;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * The dealers' PENDING CBC (user 2026-11-05): on live the dealership coupons were approved
 * before the dealership rule existed, so each was paid by the member split — 50 % to the cash
 * wallet, 5 % TDS, 5 % service, 40 % retained by Head Office. The dealership rule (Regional /
 * Zonal / District / Taluka: the whole coupon, 10 % TDS withheld) gives 90 %. The balance —
 * the retained 40 % — is what is still owed to each dealer.
 *
 * These coupons are already "paid", so Commission Approval does not list them. This command is
 * the report and the payment:
 *
 *   php artisan cbc:dealer-balance                          report (nothing written)
 *   php artisan cbc:dealer-balance --csv=storage/app/dealer-cbc-balance.csv
 *   php artisan cbc:dealer-balance --from=2026-11-01 --to=2026-11-30 --apply
 *
 * --apply credits each balance to the dealer's cash wallet, re-stamps the coupon with the
 * dealership split (cash 90 %, TDS 10 %, service 0, retained 0) and sends one push per dealer.
 * A second run finds nothing left. Coupons paid in legacy (no split on the row) are never
 * touched; coupons credited ABOVE the rule are listed, never taken back.
 */
class CbcDealerBalance extends Command
{
    protected $signature = 'cbc:dealer-balance {--from= : coupon date from (Y-m-d)} {--to= : coupon date to (Y-m-d)}
        {--csv= : also write the report to this file} {--apply : pay the balances (default = report only)}';

    protected $description = 'Report / pay the balance owed on dealership CBC coupons that were approved by the member split (50 %)';

    public function handle(CommissionApprovalService $svc): int
    {
        $apply = (bool) $this->option('apply');
        $from = $this->option('from') ?: null;
        $to = $this->option('to') ?: null;

        $planCodes = Plan::where('cbc_dealer_rule', true)->pluck('code', 'id');
        if ($planCodes->isEmpty()) {
            $this->error('No scheme is on the dealership rule (Master → Plans → "Pay the coupon by the dealership rule").');

            return self::INVALID;
        }
        $bondPlan = Bond::whereIn('plan_id', $planCodes->keys())->pluck('plan_id', 'id');

        $coupons = CbcEntry::query()
            ->where('status', 'paid')
            ->where('cash_amount', '>', 0)                     // approved by the new system — legacy-paid rows carry no split
            ->whereIn('bond_id', $bondPlan->keys())
            ->when($from, fn ($q) => $q->whereDate('cbc_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('cbc_date', '<=', $to))
            ->orderBy('member_id')->orderBy('cbc_date')
            ->get();

        $perMember = [];
        $over = [];
        foreach ($coupons as $c) {
            $b = CommissionApprovalService::dealerCbcBalance($c);
            if ($b['balance'] < -0.005) {
                $over[] = [$c->member_id, $c->code, $c->cbc_date->format('d M Y'), number_format($b['worth'], 2), number_format($b['paid'], 2), number_format(-$b['balance'], 2)];

                continue;
            }
            if ($b['balance'] <= 0.005) {
                continue;   // already on the dealership split
            }
            $m = &$perMember[$c->member_id];
            $m ??= ['coupons' => 0, 'worth' => 0.0, 'paid' => 0.0, 'withheld' => 0.0, 'tds' => 0.0, 'balance' => 0.0, 'plans' => [], 'rows' => []];
            $m['coupons']++;
            $m['worth'] += $b['worth'];
            $m['paid'] += $b['paid'];
            $m['withheld'] += (float) $c->tds + (float) $c->service_charge;
            $m['tds'] += $b['target']['tds'];
            $m['balance'] += $b['balance'];
            $m['plans'][$planCodes[$bondPlan[$c->bond_id]] ?? '?'] = true;
            $m['rows'][] = $c;
            unset($m);
        }

        $members = Member::whereIn('id', array_keys($perMember))->get(['id', 'member_code', 'name'])->keyBy('id');
        $table = [];
        $tot = ['coupons' => 0, 'worth' => 0.0, 'paid' => 0.0, 'tds' => 0.0, 'balance' => 0.0];
        uasort($perMember, fn ($a, $b) => $b['balance'] <=> $a['balance']);
        foreach ($perMember as $id => $m) {
            $table[] = [
                $members[$id]->member_code ?? ('#' . $id), $members[$id]->name ?? '', implode(' ', array_keys($m['plans'])), $m['coupons'],
                number_format($m['worth'], 2), number_format($m['paid'], 2), number_format($m['tds'], 2), number_format($m['balance'], 2),
            ];
            foreach (['coupons', 'worth', 'paid', 'tds', 'balance'] as $k) {
                $tot[$k] += $m[$k];
            }
        }

        $headers = ['User ID', 'Dealer', 'Scheme', 'Coupons', 'Coupon worth', 'Already paid', 'TDS (10 %)', 'Balance to pay'];
        $this->info(sprintf('%s — dealership coupons approved by the member split%s: %d coupon(s) of %d dealer(s)',
            $apply ? 'APPLY' : 'REPORT', ($from || $to) ? ' (' . ($from ?? '…') . ' → ' . ($to ?? '…') . ')' : '', $tot['coupons'], count($perMember)));
        if ($table) {
            $this->table($headers, $table);
            $this->line(sprintf('  coupon worth ₹%s · already paid ₹%s · TDS under the dealership rule ₹%s · BALANCE TO PAY ₹%s',
                number_format($tot['worth'], 2), number_format($tot['paid'], 2), number_format($tot['tds'], 2), number_format($tot['balance'], 2)));
        }
        if ($over) {
            $this->warn(count($over) . ' coupon(s) were credited ABOVE the dealership rule (approved in full) — listed only, nothing is taken back:');
            $this->table(['Member id', 'Coupon', 'Date', 'Worth', 'Credited', 'Over by'], $over);
        }

        if ($csv = $this->option('csv')) {
            $fh = fopen($csv, 'w');
            fputcsv($fh, $headers);
            foreach ($table as $r) {
                // plain numbers in the money columns so the sheet can total them
                fputcsv($fh, array_merge(array_slice($r, 0, 4), array_map(fn ($v) => str_replace(',', '', (string) $v), array_slice($r, 4))));
            }
            fclose($fh);
            $this->line("  report written to {$csv}");
        }

        if (! $apply) {
            if ($table) {
                $this->comment('Report only — nothing written. Add --apply to credit the balances to the cash wallets.');
            }

            return self::SUCCESS;
        }

        $paidTotal = 0.0;
        $paidDealers = 0;
        foreach ($perMember as $id => $m) {
            $credited = 0.0;
            foreach ($m['rows'] as $row) {
                $credited += $svc->settleDealerCbcBalance($row);
            }
            if ($credited > 0) {
                $paidTotal += $credited;
                $paidDealers++;
                \App\Services\Push\Notifier::to(Member::find($id), 'commission', 'Commission credited to your wallet',
                    'Dealership promotional incentive balance — ₹' . Money::group($credited) . ' credited to your cash wallet.', route: '/wallet');
            }
        }
        $this->info(sprintf('Credited ₹%s to %d dealer wallet(s).', number_format($paidTotal, 2), $paidDealers));
        if ($paidTotal > 0) {
            ActivityLogger::log('commission', 'cbc_dealer_balance',
                'Dealership CBC balance paid: ₹' . Money::group($paidTotal) . " to {$paidDealers} dealer(s) ({$tot['coupons']} coupons re-stamped to the dealership split)",
                ['details' => ['from' => $from, 'to' => $to, 'coupons' => $tot['coupons'], 'dealers' => $paidDealers, 'credited' => round($paidTotal, 2)]]);
        }

        return self::SUCCESS;
    }
}
