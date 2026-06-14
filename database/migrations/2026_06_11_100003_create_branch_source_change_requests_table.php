<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distributor request to change their assigned supply source. The distributor raises
 * it; the admin approves/rejects. On approval the branch's source_branch_id is updated
 * to the requested source. Mirrors the legacy "request to admin → approve → notify" flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_source_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('current_source_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('requested_source_branch_id')->constrained('branches');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->string('note', 255)->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_source_change_requests');
    }
};
