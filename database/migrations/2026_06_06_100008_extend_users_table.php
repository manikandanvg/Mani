<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Extend the stock users table for back-office staff/admins (Filament logs in here).
// Roles/permissions handled by spatie; these columns add profile + scoping + i18n.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->enum('status', ['active', 'inactive', 'blocked'])->default('active')->after('phone');
            $table->foreignId('branch_id')->nullable()->after('status')->constrained('branches')->nullOnDelete();
            $table->string('locale', 10)->default('en')->after('branch_id');
            $table->char('currency_code', 3)->default('INR')->after('locale');
            $table->string('avatar_path', 255)->nullable()->after('currency_code');
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['phone', 'status', 'locale', 'currency_code', 'avatar_path', 'deleted_at']);
        });
    }
};
