<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each branch owns ONE RFID card (held by the incharge): tapping it on the
 * branch L-BOX opens the branch in the morning and stamps closing in the
 * evening — independent of employee attendance cards. HQ replaces the UID
 * here when a card is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('rfid_tag', 32)->nullable()->unique()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('rfid_tag');
        });
    }
};
