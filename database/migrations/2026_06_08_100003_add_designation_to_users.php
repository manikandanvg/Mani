<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A distributor's rank (Zonal / District / Taluk / Wholesaler / Reseller / Area Dealer)
 * — the legacy ci_users.desiname. Assigned from the billing plan via the hierarchy.
 * member_code links the login back to its member record (legacy ci_users.mapid).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('designation')->nullable()->after('branch_id');
            $table->string('member_code')->nullable()->after('designation');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['designation', 'member_code']);
        });
    }
};
