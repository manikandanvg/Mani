<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Monthly cut-off record (docs/CUTOFF-PLAN.md): one row per `payout:cutoff` run — the
 * legacy Ascript "generate" step with its month report. GAP ledger rows and the CBC
 * coupons that fell due carry the run id so the viewer, the approval and the parity
 * comparison can address exactly one run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_runs', function (Blueprint $table) {
            $table->id();
            $table->date('period')->index();                       // 1st of the pay month (legacy `dos`)
            $table->date('cutoff_date');                           // bonds on or before this date (legacy `$bdateact`)
            $table->string('status', 12)->default('generated')->index();   // generated | approved
            $table->unsignedInteger('gap_bonds')->default(0);      // bonds that consumed an instalment
            $table->unsignedInteger('gap_rows')->default(0);       // ledger rows (one per level per bond)
            $table->unsignedInteger('gap_members')->default(0);    // distinct earners
            $table->decimal('gap_gross', 15, 2)->default(0);
            $table->unsignedInteger('cbc_rows')->default(0);       // coupons due in the period
            $table->decimal('cbc_worth', 15, 2)->default(0);
            $table->json('options')->nullable();                   // ep_cap / task_factor / recompute switches
            $table->string('log_path', 200)->nullable();
            $table->foreignId('run_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // plain indexed columns (no FK): a constrained column makes sqlite rebuild the table,
        // which the commission_entries view forbids; runs are never deleted anyway.
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->unsignedBigInteger('payout_run_id')->nullable()->index();
        });
        Schema::table('cbc_entries', function (Blueprint $table) {
            $table->unsignedBigInteger('payout_run_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('cbc_entries', function (Blueprint $table) {
            $table->dropColumn('payout_run_id');
        });
        Schema::table('commission_ledger', function (Blueprint $table) {
            $table->dropColumn('payout_run_id');
        });
        Schema::dropIfExists('payout_runs');
    }
};
