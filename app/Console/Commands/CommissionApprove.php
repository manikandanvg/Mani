<?php

namespace App\Console\Commands;

use App\Services\CommissionApprovalService;
use Illuminate\Console\Command;

/**
 * Approve a commission stream from the shell — the same gate as Commission Approval
 * (wallet credit net of TDS + service, one push per beneficiary), but with no web-server
 * timeout. User 2026-11-03: the 1 Nov CBC approval died after 88 of 11,624 coupons.
 *
 *   php artisan commission:approve --type=CBC --from=2026-11-01 --to=2026-11-01 [--dry-run]
 *   php artisan commission:approve --type=CBC --plan=P214 --plan=P207 --plan=P204 --plan=P203 --plan=P213 --dry-run
 *                                   (user 2026-11-05: only the dealerships' + Sub Dealers' coupons)
 */
class CommissionApprove extends Command
{
    protected $signature = 'commission:approve {--type= : IC, GAP, CBC, BILL_MARGIN, GOLD_MARGIN, SILVER_MARGIN, STOCK_TRANSFER_MARGIN, RD_RENEWAL_MARGIN}
        {--from= : earned from (Y-m-d)} {--to= : earned to (Y-m-d)} {--plan=* : CBC only — scheme code(s), e.g. P213; default every scheme}
        {--dry-run : only count what would be approved}';

    protected $description = 'Approve every un-paid commission of a stream in a date window and credit the wallets (no web timeout)';

    public function handle(CommissionApprovalService $svc): int
    {
        $type = strtoupper((string) $this->option('type'));
        if (! isset(CommissionApprovalService::TYPES[$type])) {
            $this->error('--type must be one of: ' . implode(', ', array_keys(CommissionApprovalService::TYPES)));

            return self::INVALID;
        }
        $from = $this->option('from') ?: null;
        $to = $this->option('to') ?: null;

        $planIds = null;
        $codes = array_values(array_filter(array_map(fn ($c) => strtoupper(trim((string) $c)), (array) $this->option('plan'))));
        if ($codes) {
            if ($type !== 'CBC') {
                $this->error('--plan applies to --type=CBC only.');

                return self::INVALID;
            }
            $found = \App\Models\Plan::whereIn('code', $codes)->pluck('id', 'code');
            if ($missing = array_diff($codes, $found->keys()->all())) {
                $this->error('Unknown scheme code(s): ' . implode(', ', $missing));

                return self::INVALID;
            }
            $planIds = $found->values()->all();
            $this->line('Schemes: ' . implode(', ', $codes));
        }

        $query = $svc->query($type, $from, $to, $planIds);
        $amount = match ($type) { 'CBC' => 'worth', 'IC', 'GAP' => 'amount', default => 'com_value' };
        $rows = (int) $query->count();
        $gross = (float) $query->sum($amount);
        $this->info(sprintf('%s %s → %s: %d un-paid rows, gross ₹%s, %d beneficiaries',
            CommissionApprovalService::label($type), $from ?? '…', $to ?? '…', $rows, number_format($gross, 2),
            (int) $query->distinct()->count(CommissionApprovalService::groupColumn($type))));
        if ($type === 'CBC' && $rows > 0) {
            // the coupon worth is not what reaches the wallet — show the legacy month-report split
            // (user 2026-11-04): member plans 50 % cash + TDS/service + HQ coupon wallet; dealership
            // plans the whole coupon as cash, the TDS withheld from it (user 2026-11-05).
            $s = CommissionApprovalService::cbcSplitTotals($query);
            $m = CommissionApprovalService::cbcSplit($s['member_worth'], false);
            $d = CommissionApprovalService::cbcSplit($s['dealer_worth'], true);
            $this->line(sprintf('  member plans ₹%s → cash wallet %s%% ₹%s · TDS %s%% ₹%s · service %s%% ₹%s · coupon wallet (HQ only) ₹%s',
                number_format($s['member_worth'], 2), $m['cash_pct'], number_format($m['cash'], 2), $m['tds_pct'], number_format($m['tds'], 2), $m['service_pct'], number_format($m['service'], 2), number_format($m['coupon'], 2)));
            $this->line(sprintf('  dealership plans ₹%s → TDS %s%% ₹%s · service %s%% ₹%s withheld · cash wallet ₹%s',
                number_format($s['dealer_worth'], 2), $d['tds_pct'], number_format($d['tds'], 2), $d['service_pct'], number_format($d['service'], 2), number_format($d['cash'], 2)));
        }
        if ($rows === 0 || $this->option('dry-run')) {
            return self::SUCCESS;
        }
        if (! $this->option('no-interaction') && ! $this->confirm('Approve all of them and credit the wallets now?', true)) {
            return self::SUCCESS;
        }

        $started = microtime(true);
        $res = $svc->approveBatch($type, $from, $to, null, null, null, $planIds);
        $this->info(sprintf('Approved %d rows (gross ₹%s) for %d beneficiaries in %d s.',
            $res['rows'], number_format($res['gross'], 2), $res['beneficiaries'], (int) (microtime(true) - $started)));

        return self::SUCCESS;
    }
}
