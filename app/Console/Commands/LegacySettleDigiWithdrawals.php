<?php

namespace App\Console\Commands;

use App\Services\Activity\ActivityLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cut-over (board 2026-11-01): every digi-gold withdrawal request still PENDING in legacy
 * (tbl_digiwithdrawal → digi_withdrawals) is settled into the member's CASH wallet at the
 * rupee worth fixed when the member asked for it. In legacy the grams had already left the
 * member's purse (the import deducts every withdrawal, pending included) and a dealer was
 * expected to hand over cash at the counter; that counter leg is gone — the member withdraws
 * the cash at any dealer's static QR instead. The mirrored digi_queue DIGIWD row (the
 * dealer-side "pay this member" ticket) is closed at the same time so nobody pays twice.
 *
 *   php artisan legacy:settle-digi-withdrawals --dry-run
 *   php artisan legacy:settle-digi-withdrawals
 *
 * Idempotent: only rows still 'pending' are touched; settled rows become 'delivered'.
 */
class LegacySettleDigiWithdrawals extends Command
{
    protected $signature = 'legacy:settle-digi-withdrawals {--dry-run : list what would be credited without writing}';

    protected $description = 'Cut-over: credit pending legacy digi-gold withdrawal requests to the member cash wallet and close the matching queue rows';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $rows = DB::table('digi_withdrawals')->where('status', 'pending')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            $this->info('No pending digi-gold withdrawal requests — nothing to settle.');

            return self::SUCCESS;
        }

        $out = ['requests' => 0, 'members' => [], 'worth' => 0.0, 'grams' => 0.0, 'queue_closed' => 0, 'no_wallet' => 0];
        foreach ($rows as $w) {
            $worth = round((float) $w->worth, 2);
            if ($worth <= 0 || ! $w->member_id) {
                $this->warn(sprintf('request #%d skipped: member %s, worth %s', $w->id, $w->member_id ?? '—', number_format($worth, 2)));
                continue;
            }
            $closed = 0;
            if (! $dry) {
                $closed = DB::transaction(function () use ($w, $worth, &$out) {
                    $wallet = DB::table('member_wallets')->where('member_id', $w->member_id)->lockForUpdate()->first();
                    if (! $wallet) {
                        DB::table('member_wallets')->insert(['member_id' => $w->member_id, 'cash_balance' => 0, 'currency_code' => 'INR', 'created_at' => now(), 'updated_at' => now()]);
                        $out['no_wallet']++;
                    }
                    DB::table('member_wallets')->where('member_id', $w->member_id)->update([
                        'cash_balance' => DB::raw('cash_balance + ' . number_format($worth, 2, '.', '')),
                        'updated_at' => now(),
                    ]);
                    DB::table('digi_withdrawals')->where('id', $w->id)->update(['status' => 'delivered', 'updated_at' => now()]);

                    // the dealer-side ticket for the same request (legacy tbl_digi_queue route DIGIWD,
                    // mirrored 1:1 by member + grams) — closed so the counter never pays it in cash
                    return DB::table('digi_queue')->where('route', 'DIGIWD')->where('delivery_status', 'pending')
                        ->where('member_id', $w->member_id)
                        ->whereRaw('ABS(gram_worth - ?) < 0.0001', [(float) $w->gold_wt])
                        ->limit(1)->update(['delivery_status' => 'redeemed', 'updated_at' => now()]);
                });
            }
            $out['requests']++;
            $out['members'][$w->member_id] = true;
            $out['worth'] += $worth;
            $out['grams'] += (float) $w->gold_wt;
            $out['queue_closed'] += (int) $closed;
            $this->line(sprintf('%s member #%d  %.4f g  ₹%s', $dry ? 'would credit' : 'credited', $w->member_id, (float) $w->gold_wt, number_format($worth, 2)));
        }
        $summary = ['requests' => $out['requests'], 'members' => count($out['members']), 'grams' => round($out['grams'], 4), 'worth' => round($out['worth'], 2), 'queue_closed' => $out['queue_closed'], 'wallets_created' => $out['no_wallet']];
        $this->table(['requests', 'members', 'grams', 'worth ₹', 'queue tickets closed', 'wallets created'], [array_values($summary)]);
        if ($dry) {
            $this->info('Dry run — nothing written.');
        } else {
            ActivityLogger::log('wallet', 'legacy_digi_withdrawals_settled',
                sprintf('Cut-over: %d pending legacy digi-gold withdrawal requests (%.4f g, ₹%s) credited to %d members\' cash wallets', $summary['requests'], $summary['grams'], number_format($summary['worth'], 2), $summary['members']),
                ['details' => $summary]);
            $this->info('Done — the amounts are in the members\' cash wallets; the requests are marked delivered.');
        }

        return self::SUCCESS;
    }
}
