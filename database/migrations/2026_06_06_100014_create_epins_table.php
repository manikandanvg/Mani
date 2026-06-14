<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('epins', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->foreignId('owner_member_id')->constrained('members');
            $table->decimal('worth', 15, 2);
            $table->enum('status', ['available', 'used'])->default('available');
            $table->foreignId('used_by_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('epin_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members');
            $table->unsignedInteger('qty');
            $table->decimal('unit_price', 15, 2);
            $table->decimal('net_total', 15, 2);
            $table->string('remarks', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('epin_logs');
        Schema::dropIfExists('epins');
    }
};
