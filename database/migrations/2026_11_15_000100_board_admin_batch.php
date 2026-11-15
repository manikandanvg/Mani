<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lord's board 2026-11-15 — admin-panel batch.
 *
 *  - branch_order_requests.decision_note / cancelled_by / cancelled_at
 *        the reason Head Office gives when it rejects an order (logged + pushed to the dealer),
 *        and the dealer's own cancellation before approval.
 *  - support_messages.body_html
 *        the rich-text reply typed by the desk; `body` keeps the plain text the app shows.
 *  - plans.settlement_alloc_pct
 *        the board's "allocation" share of the bill a contract settles on:
 *          G5 Retailer      ₹1,00,000 × 50 % =   50,000 × 72 % =   36,000 gold QR
 *          G24 Wholesaler   ₹3,00,000 × 30 % =   90,000 × 80 % =   72,000 gold QR
 *          Sub Dealer       ₹1,00,000 × 75 % =   75,000 × 80 % =   60,000 gold QR
 *          G12 Forward      ₹1,00,000 × 100 % = 1,00,000 × 100 % = 1,00,000 gold QR
 *  - contract_settlements.mode / redeemable_qr_id / rule_amount
 *        a settlement is now either a wallet credit (as before) or a redeemable gold QR.
 *  - rd_settlements.rule_worth
 *        the Gold Saving settlement the rule worked out, next to the amount the admin approved.
 *  - redeemable_qrs.kind
 *        what a QR is: billing / renewal / settlement (Track QR).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branch_order_requests', function (Blueprint $t) {
            $t->string('decision_note', 500)->nullable()->after('approved_at');
            $t->unsignedBigInteger('cancelled_by')->nullable()->after('decision_note');
            $t->timestamp('cancelled_at')->nullable()->after('cancelled_by');
        });

        Schema::table('support_messages', function (Blueprint $t) {
            $t->text('body_html')->nullable()->after('body');
        });

        Schema::table('plans', function (Blueprint $t) {
            $t->decimal('settlement_alloc_pct', 6, 3)->nullable()->after('settlement_qr_pct');
        });

        Schema::table('contract_settlements', function (Blueprint $t) {
            $t->string('mode', 12)->default('wallet')->after('amount');       // wallet | gold_qr
            $t->unsignedBigInteger('redeemable_qr_id')->nullable()->after('mode');
            $t->decimal('rule_amount', 14, 2)->nullable()->after('redeemable_qr_id');
        });

        Schema::table('rd_settlements', function (Blueprint $t) {
            $t->decimal('rule_worth', 14, 2)->nullable()->after('worth');
        });

        Schema::table('redeemable_qrs', function (Blueprint $t) {
            $t->string('kind', 16)->nullable()->after('source');             // billing | renewal | settlement
        });

        // The four schemes the board listed, found by name (codes were reshuffled vs the old system).
        foreach ([
            ['%G5 Retailer%', 50, 72],
            ['%G24 Wholesaler%', 30, 80],
            ['%Sub Dealer%', 75, 80],
            ['%G12 Forward%', 100, 100],
        ] as [$like, $alloc, $pct]) {
            DB::table('plans')->where('name', 'like', $like)
                ->update(['settlement_alloc_pct' => $alloc, 'settlement_qr_pct' => $pct]);
        }

        // Settlement QRs already issued by the Gold Saving settlement are labelled as such.
        DB::table('redeemable_qrs')
            ->whereIn('id', DB::table('rd_settlements')->whereNotNull('redeemable_qr_id')->pluck('redeemable_qr_id'))
            ->update(['kind' => 'settlement']);
    }

    public function down(): void
    {
        Schema::table('redeemable_qrs', fn (Blueprint $t) => $t->dropColumn('kind'));
        Schema::table('rd_settlements', fn (Blueprint $t) => $t->dropColumn('rule_worth'));
        Schema::table('contract_settlements', fn (Blueprint $t) => $t->dropColumn(['mode', 'redeemable_qr_id', 'rule_amount']));
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('settlement_alloc_pct'));
        Schema::table('support_messages', fn (Blueprint $t) => $t->dropColumn('body_html'));
        Schema::table('branch_order_requests', fn (Blueprint $t) => $t->dropColumn(['decision_note', 'cancelled_by', 'cancelled_at']));
    }
};
