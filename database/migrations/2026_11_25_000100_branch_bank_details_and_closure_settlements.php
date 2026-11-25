<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board 2026-11-25.
 *
 *  1. Branch bank details (bank name, account holder, account number, IFSC) on the branch, and a
 *     log of every change to them — Head Office monitors how often a branch swaps its bank.
 *  2. Closure settlements: a force-deleted member's contracts are CLOSED on that date and each
 *     is settled as  paid in + bonus due − already given = balance payable to the member.
 *     The member's live rows are gone by then, so the row carries its own copy of the names;
 *     deliberately no foreign key to any live table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            if (! Schema::hasColumn('branches', 'bank_name')) {
                $table->string('bank_name', 150)->nullable();
                $table->string('bank_holder', 150)->nullable();
                $table->string('bank_acno', 40)->nullable();
                $table->string('bank_ifsc', 15)->nullable();
            }
        });

        if (! Schema::hasTable('branch_bank_changes')) {
            Schema::create('branch_bank_changes', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->index();
                $table->string('old_bank_name', 150)->nullable();
                $table->string('old_bank_holder', 150)->nullable();
                $table->string('old_bank_acno', 40)->nullable();
                $table->string('old_bank_ifsc', 15)->nullable();
                $table->string('new_bank_name', 150)->nullable();
                $table->string('new_bank_holder', 150)->nullable();
                $table->string('new_bank_acno', 40)->nullable();
                $table->string('new_bank_ifsc', 15)->nullable();
                $table->boolean('is_first')->default(false);          // first entry — not counted as a change
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->string('changed_by_name', 150)->nullable();
                $table->timestamps();
            });
        }

        // a force-closed contract stays on the Settlements page: status force_closed + the day
        if (! Schema::hasColumn('member_contracts', 'force_closed_on')) {
            Schema::table('member_contracts', function (Blueprint $table) {
                $table->date('force_closed_on')->nullable();
            });
        }

        if (! Schema::hasTable('closure_settlements')) {
            Schema::create('closure_settlements', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('deleted_member_id')->nullable()->index();   // null = the member stays (one contract closed)
                $table->unsignedBigInteger('member_contract_id')->nullable()->index();  // the row on the Settlements page
                $table->unsignedBigInteger('member_id')->nullable();   // the id it had in `members`
                $table->string('member_code', 40)->index();
                $table->string('member_name', 200)->nullable();
                $table->string('member_phone', 30)->nullable();
                $table->unsignedBigInteger('bond_id')->nullable();     // the id it had in `bonds`
                $table->string('contract_no', 60)->nullable()->index();
                $table->string('invoice_no', 60)->nullable()->index();
                $table->string('plan_code', 30)->nullable();
                $table->string('plan_name', 200)->nullable();
                $table->unsignedBigInteger('branch_id')->nullable()->index();   // the branch that issued the bill
                $table->date('contract_date')->nullable();
                $table->date('closed_on')->index();
                $table->decimal('contributed', 15, 2)->default(0);     // what the member paid in on this contract
                $table->decimal('bonus', 15, 2)->default(0);           // bonus due at closure
                $table->decimal('given', 15, 2)->default(0);           // already given to the member on it
                $table->decimal('balance', 15, 2)->default(0);         // contributed + bonus − given (may be below zero)
                $table->decimal('payable', 15, 2)->default(0);         // max(balance, 0)
                $table->longText('detail')->nullable();                // the lines behind the three figures (JSON)
                $table->string('status', 20)->default('payable')->index();   // payable (waits for its settlement) | paid
                $table->decimal('paid_amount', 15, 2)->nullable();
                $table->date('paid_on')->nullable();
                $table->string('paid_note', 255)->nullable();
                $table->unsignedBigInteger('paid_by')->nullable();
                $table->string('reason', 255)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('closure_settlements');
        if (Schema::hasColumn('member_contracts', 'force_closed_on')) {
            Schema::table('member_contracts', function (Blueprint $table) {
                $table->dropColumn('force_closed_on');
            });
        }
        Schema::dropIfExists('branch_bank_changes');
        Schema::table('branches', function (Blueprint $table) {
            if (Schema::hasColumn('branches', 'bank_name')) {
                $table->dropColumn(['bank_name', 'bank_holder', 'bank_acno', 'bank_ifsc']);
            }
        });
    }
};
