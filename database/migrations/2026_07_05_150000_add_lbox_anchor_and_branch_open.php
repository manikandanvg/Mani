<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * L-BOX as the branch's anchor + daily opening ritual (user rules, 2026-07-05):
 *  - the box's FIRST GPS fix after pairing is saved as its anchor AND becomes the
 *    branch's map location; if the box later reports a position beyond the anchor
 *    radius it is DISPLACED → the branch is treated offline (withdrawals blocked)
 *  - every morning the incharge taps their RFID card: the first device tap of the
 *    day marks the branch OPEN (attendance now records WHICH box it happened at)
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->decimal('anchor_lat', 10, 7)->nullable()->after('lng');
            $table->decimal('anchor_lng', 10, 7)->nullable()->after('anchor_lat');
            $table->timestamp('anchored_at')->nullable()->after('anchor_lng');
            $table->boolean('is_displaced')->default(false)->after('anchored_at');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('marked_by')
                ->constrained()->nullOnDelete();
        });

        // BRANCH attendance: one row per branch per day, written by RFID taps at
        // that branch's box. First tap = branch OPENED (online); check-out taps
        // stamp/refresh the closing time. The daily opening/closing register.
        Schema::create('branch_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('employee_profiles')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('employee_profiles')->nullOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_attendances');
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
        });
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['anchor_lat', 'anchor_lng', 'anchored_at', 'is_displaced']);
        });
    }
};
