<?php

namespace App\Services\Auth;

use App\Models\PhoneOtp;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Support\Carbon;

/**
 * Phone OTP issuing + verification for the mobile app login (see
 * docs/mobile-app-rebuild-report.md §3). Codes are short-lived, single-use, and
 * attempt-limited. Delivery is over WhatsApp (the proven legacy gateway); SMS can
 * be added as a fallback channel later.
 */
class OtpService
{
    public const CODE_TTL_MINUTES = 5;

    public const MAX_ATTEMPTS = 5;

    /** Resend throttle — don't issue a fresh code more than once per this window. */
    public const RESEND_COOLDOWN_SECONDS = 30;

    public function __construct(protected WhatsappSender $whatsapp) {}

    /**
     * Issue a code for a phone + purpose and deliver it. Returns the OTP row.
     * In non-production environments the plain code is also returned to the caller
     * (so local/dev and tests can complete the flow without a live gateway).
     *
     * @return array{otp: PhoneOtp, sent: bool, debug_code: ?string}
     */
    public function issue(string $phone, string $purpose = 'login'): array
    {
        $phone = $this->normalize($phone);

        // Cooldown: reuse the most recent live code instead of spamming.
        $recent = $this->latestLive($phone, $purpose);
        if ($recent && $recent->created_at->gt(Carbon::now()->subSeconds(self::RESEND_COOLDOWN_SECONDS))) {
            $otp = $recent;
            $code = $otp->code;
        } else {
            $code = (string) random_int(100000, 999999);
            $otp = PhoneOtp::create([
                'phone' => $phone,
                'code' => $code,
                'purpose' => $purpose,
                'expires_at' => Carbon::now()->addMinutes(self::CODE_TTL_MINUTES),
            ]);
        }

        $result = $this->whatsapp->sendText(
            $phone,
            "Your Lord ICL verification code is {$code}. It expires in " . self::CODE_TTL_MINUTES . ' minutes. Do not share it with anyone.'
        );

        return [
            'otp' => $otp,
            'sent' => (bool) ($result['ok'] ?? false),
            'debug_code' => app()->environment('production') ? null : $code,
        ];
    }

    /**
     * Verify a submitted code for a phone + purpose. Consumes the code on success.
     * Returns true only for a matching, unexpired, unconsumed, within-attempts code.
     */
    public function verify(string $phone, string $code, string $purpose = 'login'): bool
    {
        $phone = $this->normalize($phone);
        $otp = $this->latestLive($phone, $purpose);

        if (! $otp) {
            return false;
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            return false;
        }

        $otp->increment('attempts');

        if (! hash_equals($otp->code, trim($code))) {
            return false;
        }

        $otp->update(['consumed_at' => Carbon::now()]);

        return true;
    }

    /** The newest unconsumed, unexpired OTP for a phone + purpose. */
    protected function latestLive(string $phone, string $purpose): ?PhoneOtp
    {
        return PhoneOtp::where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', Carbon::now())
            ->latest('id')
            ->first();
    }

    /** Reduce a phone to digits so lookups are consistent regardless of formatting. */
    public function normalize(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: $phone;
    }
}
