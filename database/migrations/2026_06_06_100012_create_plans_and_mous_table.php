<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Investment/savings plans + their MOU contract terms. Commission schedules stored
// as JSON arrays (per-level %), matching the legacy iccom/levelcom strings.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('mous', function (Blueprint $table) {
            $table->id();
            $table->json('title');                          // translatable
            $table->json('terms');                          // translatable rich text
            $table->unsignedInteger('duration_months')->default(12);
            $table->timestamps();
        });

        // 1.4.3 Plan (trade) products — supplied to merchants offline. Mirrors the
        // legacy tbl_planjewel. plan_type: 1 RD, 2 Digital(Cash/Gold/Silver), 3 Gold, 4 Silver.
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->json('name');                           // translatable (planname)
            $table->unsignedTinyInteger('plan_type')->default(1); // 1 RD,2 Digital,3 Gold,4 Silver
            $table->enum('type', ['rd', 'digital', 'gold', 'silver', 'others'])->default('rd');
            $table->decimal('min_value', 15, 2);            // minvalue
            $table->decimal('allocation_pct', 6, 3)->default(100); // allocation
            $table->unsignedInteger('validity_months')->default(12);
            // cashback (CBC)
            $table->decimal('cbc_value', 6, 3)->default(0); // cbvalue (% cashback)
            $table->unsignedInteger('cbc_count')->default(0); // cbcount (months)
            // commissions
            $table->unsignedTinyInteger('level_depth')->default(0); // leveldepth
            $table->json('ic_schedule')->nullable();        // iccom[10] e.g. ["10.0","3.0",...]
            $table->json('level_schedule')->nullable();     // levelcom[] e.g. ["1.25","0.5",...]
            $table->unsignedInteger('level_com_duration')->default(0); // levelcomduration (months)
            // branch margins
            $table->decimal('billing_margin', 8, 3)->default(0);    // billingmargin
            $table->decimal('gm_margin', 8, 3)->default(0);         // gmmargin (redemption branch)
            $table->decimal('stock_trans_margin', 8, 3)->default(0); // stocktransmargin
            $table->unsignedInteger('epin_count')->default(0);
            $table->foreignId('mou_id')->nullable()->constrained('mous')->nullOnDelete();
            $table->boolean('is_active')->default(true);    // planvisible (1 = billable)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plans');
        Schema::dropIfExists('mous');
    }
};
