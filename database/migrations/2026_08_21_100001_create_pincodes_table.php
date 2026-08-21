<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offline pincode master (board phase-1, 2026-08-21). One row per PIN + post
 * office; seeded from the India Post directory via `pincodes:import` and topped
 * up lazily from the live India Post API for any PIN not yet present.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pincodes', function (Blueprint $table) {
            $table->id();
            $table->string('pincode', 10)->index();
            $table->string('office', 150);
            $table->string('taluka', 120)->nullable();
            $table->string('district', 120)->nullable();
            $table->string('state', 120)->nullable();
            // where the row came from: import (bulk CSV) | api (live lookup cache)
            $table->string('source', 10)->default('import');
            $table->timestamps();

            $table->unique(['pincode', 'office']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pincodes');
    }
};
