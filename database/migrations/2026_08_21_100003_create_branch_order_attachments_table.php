<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment-proof files on a stock order (board phase-1, 2026-08-21): transaction
 * receipts, screenshots and bank slips uploaded on the Order Form, shown to the
 * HQ approver before Approve/Reject.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_order_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_request_id')->constrained('branch_order_requests')->cascadeOnDelete();
            $table->string('path', 500);            // on the public disk
            $table->string('original_name')->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_order_attachments');
    }
};
