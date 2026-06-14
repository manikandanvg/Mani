<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Global-scale: every UI string & content row can be localised. This table is the
// catalogue of enabled locales; translatable content uses JSON columns elsewhere.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();   // en, ta, hi, ar, fr...
            $table->string('name', 80);
            $table->string('native_name', 80)->nullable();
            $table->boolean('is_rtl')->default(false);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
