<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Unified commission ledger — replaces tbl_iccom / tbl_memberearning_level /
// tbl_reseller_com etc. One row per earning, typed, with a clear state machine.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('commission_ledger', function (Blueprint $table) {
            $table->id();
            $table->enum('type', [
                'IC', 'GAP', 'CBC', 'BILL_MARGIN', 'RESELLER', 'PAIRMATCH', 'REFERRAL', 'ROI', 'REWARD',
            ]);
            $table->foreignId('member_id')->constrained('members');           // beneficiary
            $table->foreignId('from_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('bond_id')->nullable()->constrained('bonds')->nullOnDelete();
            $table->string('invoice_no', 40)->nullable();
            $table->unsignedTinyInteger('level')->nullable();
            $table->decimal('amount', 15, 2);
            $table->enum('status', ['pending', 'approved', 'paid'])->default('pending');
            $table->enum('pay_via', ['digi_transfer', 'online', 'cash'])->nullable();
            $table->date('earned_on');
            $table->date('paid_on')->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->timestamps();
            $table->index(['member_id', 'status']);
            $table->index('type');
        });

        Schema::create('payout_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members');
            $table->char('period', 7);                       // YYYY-MM
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('net_total', 15, 2);
            $table->decimal('tds', 15, 2)->default(0);
            $table->decimal('service_charge', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2);
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->timestamps();
            $table->unique(['member_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_statements');
        Schema::dropIfExists('commission_ledger');
    }
};
