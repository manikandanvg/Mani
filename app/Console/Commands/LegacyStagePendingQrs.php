<?php

namespace App\Console\Commands;

use App\Models\Bond;
use App\Models\DigiQueue;
use App\Models\DigiWithdrawal;
use App\Models\RedeemableQr;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Board 2026-11-01: the legacy pending QRs are NOT settled by the cut-over — they are staged
 * for Head Office to decide on two Commissions pages.
 *
 *  Pending Gold QR  — every legacy invoice QR still awaiting delivery (digi_queue route
 *                     INVOICE) becomes a redeemable QR (source legacy) carrying the metal
 *                     price of its own date (frozen_rate). HQ sends it over WhatsApp; the
 *                     distributor redeems at ANY branch at that price. The queue row is
 *                     marked "staged" so it is never redeemed twice.
 *  Pending Cash QR  — every legacy digi-gold withdrawal request stays pending (the import
 *                     already keeps them); the dealer-side cash tickets (digi_queue route
 *                     DIGIWD) that have NO request behind them are added as requests of
 *                     source "ticket". HQ chooses cash wallet or digi-gold per row.
 *
 * Idempotent: a QR already staged (same qr_code) or a ticket already represented is skipped.
 */
class LegacyStagePendingQrs extends Command
{
    protected $signature = 'legacy:stage-pending-qrs {--dry-run : count only, write nothing}';

    protected $description = 'Stage legacy pending gold QRs as redeemable QRs and orphan cash tickets as withdrawal requests for the Commissions pages';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // ---- gold / silver QRs
        $staged = ['gold' => 0, 'silver' => 0, 'no_bond' => 0, 'skipped' => 0];
        DigiQueue::query()->where('route', 'INVOICE')->where('delivery_status', 'pending')->orderBy('id')
            ->chunkById(500, function ($rows) use (&$staged, $dry) {
                $codes = RedeemableQr::whereIn('qr_code', $rows->pluck('qr_code'))->pluck('qr_code')->flip();
                $invoices = Bond::whereIn('invoice_no', $rows->pluck('reference')->filter())->get(['id', 'invoice_no', 'member_id', 'branch_id'])
                    ->keyBy(fn ($b) => $b->invoice_no . '|' . $b->member_id);
                $batch = [];
                $ids = [];
                foreach ($rows as $q) {
                    if (isset($codes[$q->qr_code]) || ! $q->member_id) {
                        $staged['skipped']++;
                        continue;
                    }
                    $bond = $invoices->get($q->reference . '|' . $q->member_id);
                    if (! $bond) {
                        $staged['no_bond']++;
                    }
                    $grams = (float) $q->gram_worth;
                    $rate = (float) $q->rate ?: ($grams > 0 ? round((float) $q->cash_worth / $grams, 2) : null);
                    $mode = in_array($q->qr_mode, ['gold', 'silver'], true) ? $q->qr_mode : 'gold';
                    $staged[$mode]++;
                    $batch[] = [
                        'bond_id' => $bond?->id,
                        'member_id' => $q->member_id,
                        'branch_id' => $bond?->branch_id,
                        'invoice_no' => $q->reference,
                        'qr_code' => $q->qr_code,
                        'qr_mode' => $mode,
                        'source' => RedeemableQr::SOURCE_LEGACY,
                        'priced_on' => $q->created_at?->toDateString(),
                        'frozen_rate' => $rate,
                        'gram_worth' => $grams,
                        'cash_worth' => (float) $q->cash_worth,
                        'status' => 'pending',
                        'qr_sent' => (bool) $q->qr_sent,
                        'redeem_branch_id' => $q->redeem_branch_id,
                        'created_at' => $q->created_at,
                        'updated_at' => now(),
                    ];
                    $ids[] = $q->id;
                }
                if ($batch && ! $dry) {
                    DB::transaction(function () use ($batch, $ids) {
                        DB::table('redeemable_qrs')->insert($batch);
                        DB::table('digi_queue')->whereIn('id', $ids)->update(['delivery_status' => DigiQueue::STATUS_STAGED, 'updated_at' => now()]);
                    });
                }
            });

        // ---- cash tickets without a request behind them
        $tickets = ['added' => 0, 'matched' => 0];
        $pending = DigiWithdrawal::where('status', 'pending')->get(['id', 'member_id', 'gold_wt', 'qr_code', 'rate', 'worth', 'legacy_ref']);
        $byMember = $pending->groupBy('member_id');
        $known = $pending->pluck('qr_code')->filter()->flip();
        $batch = [];
        foreach (DigiQueue::query()->where('route', 'DIGIWD')->where('delivery_status', 'pending')->orderBy('id')->get() as $t) {
            if (isset($known[$t->qr_code])) {
                continue;
            }
            $match = ($byMember[$t->member_id] ?? collect())->first(fn ($w) => $w->qr_code === null && abs((float) $w->gold_wt - (float) $t->gram_worth) < 0.0001);
            if ($match) {
                // the request already exists — remember which ticket it pays
                $tickets['matched']++;
                $match->qr_code = $t->qr_code;   // taken, so a second ticket of the same grams gets its own row
                if (! $dry) {
                    DigiWithdrawal::whereKey($match->id)->update(['qr_code' => $t->qr_code, 'legacy_ref' => $match->legacy_ref ?: 'request',
                        'rate' => $match->rate ?: ((float) $match->gold_wt > 0 ? round((float) $match->worth / (float) $match->gold_wt, 2) : null)]);
                }
                continue;
            }
            $grams = (float) $t->gram_worth;
            $batch[] = [
                'member_id' => $t->member_id, 'gold_wt' => $grams, 'worth' => (float) $t->cash_worth, 'status' => 'pending',
                'source' => DigiWithdrawal::SOURCE_TICKET, 'qr_code' => $t->qr_code, 'legacy_ref' => 'ticket',
                'rate' => (float) $t->rate ?: ($grams > 0 ? round((float) $t->cash_worth / $grams, 2) : null),
                'created_at' => $t->created_at, 'updated_at' => now(),
            ];
            $tickets['added']++;
        }
        if (! $dry) {
            foreach (array_chunk($batch, 500) as $chunk) {
                DB::table('digi_withdrawals')->insert($chunk);
            }
            // rate on the plain requests that never had a ticket
            DB::table('digi_withdrawals')->whereNull('rate')->where('gold_wt', '>', 0)
                ->update(['rate' => DB::raw('ROUND(worth / gold_wt, 2)'), 'legacy_ref' => DB::raw("COALESCE(legacy_ref, 'request')")]);
        }

        $p = $dry ? '[DRY RUN] ' : '';
        $this->info(sprintf('%sPending Gold QR: %d gold + %d silver QRs staged as redeemable (%d without a contract, %d already staged).',
            $p, $staged['gold'], $staged['silver'], $staged['no_bond'], $staged['skipped']));
        $this->info(sprintf('%sPending Cash QR: %d tickets matched to their request, %d ticket-only rows added; %d requests pending in all.',
            $p, $tickets['matched'], $tickets['added'], $dry ? $pending->count() + $tickets['added'] : DigiWithdrawal::where('status', 'pending')->count()));

        return self::SUCCESS;
    }
}
