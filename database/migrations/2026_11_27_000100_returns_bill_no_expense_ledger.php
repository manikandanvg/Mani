<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Board 2026-11-27.
 *
 *  1. Stock returns: the branch names the bill the stock came on and attaches a copy of it; every
 *     line keeps its pieces and the making / wastage / GST behind its value.
 *  2. Sales returns: the bill the distributor bought the coins on.
 *  3. Expense ledger: a row is an EXPENSE (Profit & Loss) or a CAPITAL ASSET (Balance Sheet);
 *     `source_key` makes the rows written by `expenses:calibrate` repeatable (never doubled).
 *  4. TBP stage awards as given by the board (only where no value was typed yet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_returns', 'bill_no')) {
                $table->string('bill_no', 60)->nullable()->index();
                $table->json('attachments')->nullable();
            }
        });

        Schema::table('stock_return_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_return_lines', 'quantity')) {
                $table->decimal('quantity', 12, 4)->nullable();        // pieces
                $table->decimal('metal_value', 14, 2)->default(0);
                $table->decimal('making', 14, 2)->default(0);
                $table->decimal('wastage', 14, 2)->default(0);
                $table->decimal('gst', 14, 2)->default(0);
            }
        });

        Schema::table('sales_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_returns', 'bill_no')) {
                $table->string('bill_no', 60)->nullable()->index();
            }
        });

        Schema::table('expenses', function (Blueprint $table) {
            if (! Schema::hasColumn('expenses', 'kind')) {
                $table->string('kind', 12)->default('expense')->index();   // expense | asset
                $table->string('source', 20)->nullable();                  // calibration = written by expenses:calibrate
                $table->string('source_key', 120)->nullable()->unique();
            }
        });

        // TBP stage awards (board 2026-11-27). Corporate Admin = share margin, value not defined.
        $awards = [
            'TALUK_DIRECTOR' => ['100 mg gold coin', 1800],
            'DISTRICT_DIRECTOR' => ['International Tour', 50000],
            'ZONAL_DIRECTOR' => ['Car', 600000],
            'STATE_DIRECTOR' => ['House', 3000000],
            'CORPORATE_DIRECTOR' => ['Share Margin', 0],
        ];
        foreach ($awards as $code => [$title, $amount]) {
            $rank = DB::table('ranks')->where('code', $code)->first();
            if (! $rank) {
                continue;
            }
            $set = [];
            if (blank($rank->reward_title)) {
                $set['reward_title'] = json_encode(['en' => $title]);
            }
            if ((float) $rank->reward_amount <= 0 && $amount > 0) {
                $set['reward_amount'] = $amount;
            }
            if ($set) {
                DB::table('ranks')->where('id', $rank->id)->update($set + ['updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            if (Schema::hasColumn('expenses', 'kind')) {
                $table->dropUnique(['source_key']);
                $table->dropColumn(['kind', 'source', 'source_key']);
            }
        });
        Schema::table('sales_returns', function (Blueprint $table) {
            if (Schema::hasColumn('sales_returns', 'bill_no')) {
                $table->dropColumn('bill_no');
            }
        });
        Schema::table('stock_return_lines', function (Blueprint $table) {
            if (Schema::hasColumn('stock_return_lines', 'quantity')) {
                $table->dropColumn(['quantity', 'metal_value', 'making', 'wastage', 'gst']);
            }
        });
        Schema::table('stock_returns', function (Blueprint $table) {
            if (Schema::hasColumn('stock_returns', 'bill_no')) {
                $table->dropColumn(['bill_no', 'attachments']);
            }
        });
    }
};
