<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lord's board, scheme allocation sheet approved 2026-11-18 (per ₹1,00,000 bill):
 *
 *                       BV        Ranking BV   Gold QR    Contract   EP
 *   Dealerships         1,00,000    20,000      80,000     20,000    20,000   (EP was 80,000)
 *   G24 Wholesaler      1,00,000    30,000      70,000     30,000    30,000   (EP was 70,000)
 *   G5 Retailer         1,00,000    50,000      50,000     50,000    50,000
 *   Sub Dealer          1,00,000    75,000      25,000     75,000    75,000   (migration 000100)
 *   Area Distributor    1,00,000    10,000        —          —       10,000
 *   G12 / G36 / PLUS 3 / PLUS 2     full        —       1,00,000  1,00,000
 *   Gold Saving PLUS 1  1,00,000         0     100 mg QR 1,00,000         0   (EP was the bond)
 *   G10 Gold Purchase   1,00,000         0     the gold      —            0   (EP was the bond)
 *   G10 Silver Purchase 1,00,000         0     the silver    —            0   (EP + ranking BV were full)
 *
 * One rule now: EP = the CONTRACT share of the bill = the ranking BV share.
 *
 *  1. plans.allocation (bond = EP share): dealerships 80 → 20, G24 Wholesaler 70 → 30, and every
 *     bond still stored at the old share is restated in the same step (the code derives an
 *     old-system bill as bond ÷ share, so a plan and its bonds must never disagree).
 *  2. plans.counts_for_ep (new): off for Gold Saving PLUS 1 and the two G10 purchase plans. Their
 *     bond value is the instalment / the bill and is used by the Gold Saving engine, so it is
 *     NOT set to 0 — the EP sum simply skips these schemes (Bond::scopeEpBearing).
 *  3. G10 Silver Purchase no longer counts for the ranking BV (run the BV recompute afterwards).
 */
return new class extends Migration
{
    /** [name like, old share %, new share %] */
    private const SHARES = [
        ['%Dealership%', 80.0, 20.0],
        ['%G24 Wholesaler%', 70.0, 30.0],
    ];

    private const NO_EP = ['%PLUS1%', '%G10 Gold Purchase%', '%G10 Silver Purchase%'];

    public function up(): void
    {
        if (! Schema::hasColumn('plans', 'counts_for_ep')) {
            Schema::table('plans', function (Blueprint $table) {
                $table->boolean('counts_for_ep')->default(true)->after('counts_for_rank')
                    ->comment('A bond of this scheme adds to the member\'s Earning Potential');
            });
        }

        DB::transaction(function () {
            foreach (self::SHARES as [$like, $from, $to]) {
                $this->restate($like, $from, $to);
            }
            foreach (self::NO_EP as $like) {
                DB::table('plans')->where('name', 'like', $like)->update(['counts_for_ep' => false]);
            }
            DB::table('plans')->where('name', 'like', '%G10 Silver Purchase%')->update(['counts_for_rank' => false, 'rank_factor' => 0]);
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            foreach (self::SHARES as [$like, $from, $to]) {
                $this->restate($like, $to, $from);
            }
            DB::table('plans')->where('name', 'like', '%G10 Silver Purchase%')->update(['counts_for_rank' => true, 'rank_factor' => 100]);
        });
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('counts_for_ep'));
    }

    /** Set the scheme's bond share and move every bond that sits at the old share to the new one. */
    private function restate(string $like, float $from, float $to): void
    {
        $planIds = DB::table('plans')->where('name', 'like', $like)->pluck('id')->all();
        if (! $planIds) {
            return;
        }
        DB::table('plans')->whereIn('id', $planIds)->update(['allocation' => $to]);

        DB::table('bonds')->whereIn('plan_id', $planIds)->where('value', '>', 0)->orderBy('id')
            ->chunkById(500, function ($bonds) use ($from, $to) {
                $invoices = DB::table('sales_invoices')
                    ->whereIn('invoice_no', $bonds->pluck('invoice_no')->filter()->all())
                    ->get(['invoice_no', 'cross_total', 'fx_rate'])->keyBy('invoice_no');

                foreach ($bonds as $b) {
                    $value = (float) $b->value;
                    $epin = (float) $b->epin_value;
                    $inv = $invoices->get($b->invoice_no);
                    $cross = $inv ? round((float) $inv->cross_total * (float) ($inv->fx_rate ?: 1), 2) : 0.0;
                    // the bill before tax: on the invoice for a new-system bill, else the stored share scaled up
                    $base = (str_starts_with((string) $b->invoice_no, 'INV-') && $cross > 0) ? $cross : round($value * 100 / $from, 2);

                    // Only a bond that really sits at the OLD share of its bill. Bonds booked under
                    // another share (old G24 bonds at 30 %, a few at 100 %) are left as they are.
                    if (abs($value - round($base * $from / 100, 2)) > 1.0) {
                        continue;
                    }
                    if ($epin > 0) {
                        $atOld = $epin * $from / 100;                    // epin may include tax: allow up to 3.5 % below
                        if ($value > $atOld + 1.0 || $value < $atOld * 0.965 - 1.0) {
                            continue;
                        }
                    }
                    DB::table('bonds')->where('id', $b->id)->update(['value' => round($base * $to / 100, 2)]);
                }
            });
    }
};
