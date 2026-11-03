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
 */
class CommissionApprove extends Command
{
    protected $signature = 'commission:approve {--type= : IC, GAP, CBC, BILL_MARGIN, GOLD_MARGIN, SILVER_MARGIN, STOCK_TRANSFER_MARGIN, RD_RENEWAL_MARGIN}
        {--from= : earned from (Y-m-d)} {--to= : earned to (Y-m-d)} {--dry-run : only count what would be approved}';

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

        $query = $svc->query($type, $from, $to);
        $amount = match ($type) { 'CBC' => 'worth', 'IC', 'GAP' => 'amount', default => 'com_value' };
        $rows = (int) $query->count();
        $gross = (float) $query->sum($amount);
        $this->info(sprintf('%s %s → %s: %d un-paid rows, gross ₹%s, %d beneficiaries',
            CommissionApprovalService::label($type), $from ?? '…', $to ?? '…', $rows, number_format($gross, 2),
            (int) $query->distinct()->count(CommissionApprovalService::groupColumn($type))));
        if ($type === 'CBC' && $rows > 0) {
            // the coupon worth is not what reaches the wallet — show the board's split
            $s = CommissionApprovalService::cbcSplit($gross);
            $this->line(sprintf('  → cash wallet %s%% ₹%s · TDS %s%% ₹%s · service %s%% ₹%s · retained by HQ (not paid) ₹%s',
                $s['cash_pct'], number_format($s['cash'], 2), $s['tds_pct'], number_format($s['tds'], 2), $s['service_pct'], number_format($s['service'], 2), number_format($s['coupon'], 2)));
        }
        if ($rows === 0 || $this->option('dry-run')) {
            return self::SUCCESS;
        }
        if (! $this->option('no-interaction') && ! $this->confirm('Approve all of them and credit the wallets now?', true)) {
            return self::SUCCESS;
        }

        $started = microtime(true);
        $res = $svc->approveBatch($type, $from, $to, null, null, null);
        $this->info(sprintf('Approved %d rows (gross ₹%s) for %d beneficiaries in %d s.',
            $res['rows'], number_format($res['gross'], 2), $res['beneficiaries'], (int) (microtime(true) - $started)));

        return self::SUCCESS;
    }
}
