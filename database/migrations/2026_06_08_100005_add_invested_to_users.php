<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A distributor's invested amount (legacy ci_users.invested). Combined with their linked
 * member's BV it drives the order limit: max(member BV, invested).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('invested', 15, 2)->default(0)->after('member_code');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('invested');
        });
    }
};
