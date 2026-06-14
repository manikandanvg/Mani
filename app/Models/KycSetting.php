<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycSetting extends Model
{
    protected $guarded = [];

    protected $casts = ['aadhaar_otp_enabled' => 'boolean'];

    /** The single settings row (created on first access, OTP off by default). */
    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1], ['aadhaar_otp_enabled' => false]);
    }

    public static function aadhaarOtpEnabled(): bool
    {
        try {
            return (bool) static::current()->aadhaar_otp_enabled;
        } catch (\Throwable) {
            return false; // table not migrated yet
        }
    }
}
