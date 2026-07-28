<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RD renewal margins were written with com_type_id 2, colliding with Gold Margin and
 * surfacing in the wrong approval queue. They are distinguishable by their synthetic
 * invoice number ("RD-{bond}-{n}", RdCollectionService) — move them to the new id 5.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('reseller_commissions')
            ->where('com_type_id', 2)
            ->where('invoice_no', 'like', 'RD-%')
            ->update(['com_type_id' => 5]);
    }

    public function down(): void
    {
        DB::table('reseller_commissions')
            ->where('com_type_id', 5)
            ->update(['com_type_id' => 2]);
    }
};
