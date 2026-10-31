<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board 2026-10-31 (items 12–14): premium copy for every public page except About Us and a
 * portal-wide FAQ (seeded here so an existing install gets them on `migrate`), plus the
 * source columns that let Play Store reviews be imported as testimonials idempotently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            $table->string('source', 30)->nullable()->after('image_path');      // google_play | null (manual)
            $table->string('source_ref', 120)->nullable()->after('source');     // review id
            $table->unique(['source', 'source_ref']);
        });

        if (Schema::hasTable('cms_pages') && Schema::hasTable('faqs')) {
            (new \Database\Seeders\CmsContentSeeder)->run();
        }
    }

    public function down(): void
    {
        Schema::table('testimonials', function (Blueprint $table) {
            $table->dropUnique(['source', 'source_ref']);
            $table->dropColumn(['source', 'source_ref']);
        });
    }
};
