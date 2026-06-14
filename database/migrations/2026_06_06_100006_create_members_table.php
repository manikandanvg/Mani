<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Member records (the MLM network). Members no longer log in (admin-managed), but
// the network/commission data lives here. Self-referencing upline tree.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('member_code', 30)->unique();
            $table->date('joined_on');
            $table->foreignId('upline_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('referrer_id')->nullable()->constrained('members')->nullOnDelete();
            $table->enum('placement', ['binary', 'level'])->default('level');
            $table->foreignId('left_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('right_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('rank_id')->constrained('ranks');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // contact + KYC (consider field-level encryption for pan/aadhaar/bank)
            $table->string('name', 200);
            $table->string('phone', 20);
            $table->string('email', 150)->nullable();
            $table->date('dob')->nullable();
            $table->string('father_name', 200)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('pincode', 12)->nullable();
            $table->char('country', 2)->default('IN');
            $table->string('pan', 15)->nullable();
            $table->string('aadhaar', 16)->nullable();
            $table->string('bank_name', 150)->nullable();
            $table->string('bank_acno', 30)->nullable();
            $table->string('ifsc', 15)->nullable();
            $table->string('upi', 80)->nullable();
            $table->string('nominee_name', 200)->nullable();
            $table->unsignedTinyInteger('nominee_age')->nullable();
            $table->string('nominee_relation', 60)->nullable();
            $table->string('nominee_phone', 20)->nullable();
            $table->string('photo_path', 255)->nullable();

            // recomputed aggregates (batch engine)
            $table->decimal('bv', 15, 2)->default(0);
            $table->decimal('gbv', 18, 2)->default(0);
            $table->decimal('unpure_bv', 15, 2)->default(0);
            $table->decimal('unpure_gbv', 18, 2)->default(0);
            $table->unsignedInteger('downline_count')->default(0);

            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->softDeletes();

            $table->index('upline_id');
            $table->index('rank_id');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
