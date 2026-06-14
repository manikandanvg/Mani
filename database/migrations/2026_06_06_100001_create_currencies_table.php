<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Global-scale: multi-currency. Amounts are stored in a base currency; display
// conversion uses the rate captured here. ISO 4217 codes.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();            // INR, USD, EUR...
            $table->string('name', 80);
            $table->string('symbol', 8);
            $table->unsignedTinyInteger('decimals')->default(2);
            $table->decimal('rate_to_base', 18, 8)->default(1); // 1 base = rate units of this currency
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
