<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycSetting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'aadhaar_otp_enabled' => 'boolean',
        'rekyc_enabled' => 'boolean',
        'rekyc_from' => 'date',
        'rekyc_until' => 'date',
    ];

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

    /**
     * Overlay the PAN / Sandbox API config with the admin-entered values (DB-first,
     * .env fallback — same convention as PushSetting::fcm()). Called when a verifier
     * resolves, so SandboxAuth and both PAN/Aadhaar drivers pick the values up.
     */
    public static function applyPanConfig(): void
    {
        try {
            $s = static::current();
            $overlay = array_filter([
                'services.pan.driver' => $s->pan_driver ?: null,
                'services.pan.key' => $s->sandbox_key ?: null,
                'services.pan.secret' => $s->sandbox_secret ?: null,
            ]);
            if ($overlay !== []) {
                config($overlay);
            }
        } catch (\Throwable) {
            // table not migrated yet — .env values stay in force
        }
    }

    /** Re-KYC campaign live right now? (enabled + today inside the date window). */
    public static function rekycActive(): bool
    {
        try {
            $s = static::current();
            if (! $s->rekyc_enabled) {
                return false;
            }
            $today = now()->startOfDay();
            if ($s->rekyc_from && $today->lt($s->rekyc_from)) {
                return false;
            }
            if ($s->rekyc_until && $today->gt($s->rekyc_until)) {
                return false;
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Does this member still owe the current Re-KYC round? PAN must be digitally
     * verified and Aadhaar approved, both ON OR AFTER the window start (an old
     * verification does not satisfy a re-verification campaign).
     */
    public static function rekycRequiredFor(Member $member): bool
    {
        if (! static::rekycActive()) {
            return false;
        }
        $from = static::current()->rekyc_from;

        $panOk = $member->pan_verified
            && (! $from || ($member->pan_verified_at && $member->pan_verified_at->gte($from)));
        $aadhaarOk = $member->aadhaar_verified
            && (! $from || ($member->aadhaar_verified_at && $member->aadhaar_verified_at->gte($from)));

        return ! ($panOk && $aadhaarOk);
    }
}
