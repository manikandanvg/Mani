<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Currency-stamping for the future multi-currency phase (e.g. a London/EUR distributor).
 *
 * Today everything is INR and the admin operates from India, so every column defaults to
 * INR / rate 1.0 and existing rows are correct as-is. The point is to record, on each
 * financial document, the currency it actually happened in AND the FX rate to the INR
 * base FROZEN at that moment — so a foreign distributor's invoice can later be issued and
 * reprinted in their own currency without re-deriving it from today's rate, while HQ still
 * consolidates everything to INR. (Mirrors what the e-commerce `orders` table already does.)
 *
 * This migration is schema-only: no pricing logic changes. When a non-INR branch goes live,
 * the sales/redemption services will set these from the branch's operating currency.
 */
return new class extends Migration
{
    /** Transaction documents: stamp both the currency and the frozen rate to INR. */
    private array $documents = [
        'sales_invoices',
        'bonds',
        'rd_entries',
        'commission_ledger',
        'redemption_invoices',
        'payout_statements',
    ];

    public function up(): void
    {
        // A branch's operating currency — the anchor for everything its distributor does.
        Schema::table('branches', function (Blueprint $table) {
            $table->char('currency_code', 3)->default('INR');
        });

        // A wallet holds a balance in its own currency.
        Schema::table('member_wallets', function (Blueprint $table) {
            $table->char('currency_code', 3)->default('INR');
        });

        foreach ($this->documents as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->char('currency_code', 3)->default('INR');
                // 1 unit of this currency = fx_rate INR, captured when the document was raised.
                $table->decimal('fx_rate', 18, 6)->default(1);
            });
        }
    }

    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn('currency_code'));
        Schema::table('member_wallets', fn (Blueprint $table) => $table->dropColumn('currency_code'));

        foreach ($this->documents as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['currency_code', 'fx_rate']));
        }
    }
};
