<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-11-28: a sales return against a bill settles with Head Office when the admin confirms the
 * coins — the amount credited to the branch's Digi cash is kept on the return.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_returns', 'settled_amount')) {
                $table->decimal('settled_amount', 14, 2)->nullable()->after('status');
            }
            if (! Schema::hasColumn('sales_returns', 'settled_at')) {
                $table->timestamp('settled_at')->nullable()->after('settled_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            foreach (['settled_amount', 'settled_at'] as $col) {
                if (Schema::hasColumn('sales_returns', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
