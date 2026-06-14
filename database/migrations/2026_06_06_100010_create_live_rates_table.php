<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Live metal rates per gram in base currency. New row per change = price history.
return new class extends Migration {
    public function up(): void
    {
        // 1.4.2 Live metal rates per gram, PER COUNTRY (rates vary by country and FX).
        // New row per change = price history. source=api (fetched) or manual (admin
        // override); is_override marks an admin-edited value.
        Schema::create('live_rates', function (Blueprint $table) {
            $table->id();
            $table->char('country', 2)->default('IN');
            $table->decimal('gold', 12, 2)->default(0);     // per gram
            $table->decimal('silver', 12, 2)->default(0);
            $table->decimal('diamond', 12, 2)->default(0);
            $table->enum('source', ['api', 'manual'])->default('manual');
            $table->boolean('is_override')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('effective_at')->useCurrent();
            $table->timestamps();
            $table->index(['country', 'effective_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_rates');
    }
};
