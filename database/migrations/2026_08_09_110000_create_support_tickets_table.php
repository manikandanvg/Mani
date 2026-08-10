<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Support desk tickets (board spec 2026-08-09). A SEPARATE module from support chat
// (support_threads): tickets are formal, assignable work items with priority and a
// reply trail; chat stays the live conversation channel. Sidebar shows two entries —
// "Open Tickets" / "Closed Tickets" — both backed by this one table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_no', 20)->unique();                       // TKT-000001
            $table->string('subject', 200);
            $table->string('category', 30)->nullable();                      // general|billing|scheme|stock|app|device
            $table->string('priority', 10)->default('medium');               // low|medium|high|urgent
            $table->string('status', 10)->default('open');                   // open|closed
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();  // distributor the issue concerns
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();  // support staff owner
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'priority']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::create('support_ticket_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_replies');
        Schema::dropIfExists('support_tickets');
    }
};
