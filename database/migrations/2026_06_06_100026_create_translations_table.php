<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// On-the-fly translation cache: each source string is translated once per locale via
// the configured translation API, then served from here. Adding a new language needs
// no manual translation — the cache fills on first view.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 8);
            $table->char('source_hash', 40);          // sha1 of source text
            $table->text('source_text');
            $table->mediumText('text');                 // translated
            $table->timestamps();
            $table->unique(['locale', 'source_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translations');
    }
};
