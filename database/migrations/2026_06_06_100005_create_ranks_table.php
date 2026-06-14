<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Member achievement tiers. Live system uses 5 (MEMBER..STATE DIRECTOR) but kept
// configurable. tier_template holds the GAP qualification thresholds (top-N GBV).
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ranks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->json('name');                       // translatable {en:.., ta:..}
            $table->unsignedInteger('depth')->default(0);
            $table->decimal('target_bv', 15, 2)->default(0);
            $table->decimal('reward_amount', 15, 2)->default(0);
            $table->json('tier_template')->nullable();  // e.g. [500000,500000,0,0,0]
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ranks');
    }
};
