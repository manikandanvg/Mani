<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Board 2026-08-11 (legacy parity): every branch — HQ included — runs its OWN
// billing serial series. The prefix makes each branch's numbers distinct
// (INV-HQ-0001, INV-B210-0001 …) and admin-editable per branch.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('invoice_prefix', 10)->nullable()->after('gst_no');
        });

        DB::table('branches')->where('id', 1)->update(['invoice_prefix' => 'HQ']);
        foreach (DB::table('branches')->where('id', '!=', 1)->pluck('id') as $id) {
            DB::table('branches')->where('id', $id)->update(['invoice_prefix' => 'B' . $id]);
        }
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('invoice_prefix');
        });
    }
};
