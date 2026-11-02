<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Board 2026-11-02: no OTP of any kind. A phone number is proven by a MISSED CALL from that
 * number to our Exotel virtual number; Exotel's webhook reports the caller and the server
 * matches it with the pending verification. One row per attempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 15);                              // digits only, last 10 = Indian subscriber number
            $table->string('purpose', 30)->default('registration');   // registration | number_change | device
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('device_uid', 80)->nullable();
            $table->string('provider', 20)->default('exotel');
            $table->string('dial_number', 20);                        // the virtual number shown to the user
            $table->string('status', 20)->default('pending');         // pending | verified | expired | failed
            $table->string('caller', 20)->nullable();                 // number Exotel reported
            $table->string('call_sid', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->string('client_ip', 45)->nullable();
            $table->timestamps();
            $table->index(['phone', 'status', 'expires_at']);
            $table->index('call_sid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verifications');
    }
};
