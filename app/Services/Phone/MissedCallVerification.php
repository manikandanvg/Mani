<?php

namespace App\Services\Phone;

use App\Models\Member;
use App\Models\PhoneVerification;
use App\Services\Activity\ActivityLogger;
use Illuminate\Support\Facades\Http;

/**
 * Missed-call number verification through Exotel (board 2026-11-02: no OTP of any kind).
 *
 *   start()    the app asks to verify a number → a pending row with the virtual number to dial
 *   webhook()  Exotel's Passthru applet reports an incoming call → the latest pending row for
 *              that caller number (within its window) becomes verified
 *   status()   the app polls until verified / expired
 *
 * Numbers are compared on their last 10 digits (Indian subscriber number), so "+91…", "0…" and
 * bare 10-digit forms all match. Nothing is sent to the user: the call is theirs, cut after one
 * ring by Exotel, so it costs them nothing.
 */
class MissedCallVerification
{
    public static function digits(?string $phone): string
    {
        $d = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return strlen($d) > 10 ? substr($d, -10) : $d;
    }

    public static function configured(): bool
    {
        $c = config('services.exotel');

        return filled($c['sid'] ?? null) && filled($c['api_key'] ?? null) && filled($c['api_token'] ?? null) && filled($c['virtual_number'] ?? null);
    }

    /** Begin a verification for a registered distributor number. */
    public function start(string $phone, string $purpose = 'registration', ?string $deviceUid = null, ?string $ip = null): PhoneVerification
    {
        abort_unless(self::configured(), 503, 'Phone verification is not set up yet (Exotel).');
        $digits = self::digits($phone);
        abort_unless(strlen($digits) === 10, 422, 'Enter the 10-digit mobile number.');
        $member = Member::where('phone', 'like', '%' . $digits)->where('status', 'active')->orderBy('id')->first();
        abort_unless($member, 422, 'This number is not registered with Lord Jeweller. Contact your dealer.');

        // one open attempt per number: reuse it while it is still valid
        $open = PhoneVerification::where('phone', $digits)->where('status', PhoneVerification::STATUS_PENDING)
            ->where('expires_at', '>', now())->latest('id')->first();
        if ($open) {
            return $open;
        }

        return PhoneVerification::create([
            'phone' => $digits, 'purpose' => $purpose, 'member_id' => $member->id, 'device_uid' => $deviceUid,
            'provider' => 'exotel', 'dial_number' => (string) config('services.exotel.virtual_number'),
            'status' => PhoneVerification::STATUS_PENDING,
            'expires_at' => now()->addMinutes((int) config('services.exotel.verification_minutes', 10)),
            'client_ip' => $ip,
        ]);
    }

    /**
     * Exotel Passthru parameters (GET): CallSid, CallFrom, CallTo, Direction, Created, … — only the
     * caller matters. Returns the verified row, or null when no pending attempt matches (logged).
     */
    public function webhook(array $params): ?PhoneVerification
    {
        $caller = self::digits($params['CallFrom'] ?? $params['From'] ?? $params['caller'] ?? null);
        $sid = (string) ($params['CallSid'] ?? '');
        if (strlen($caller) !== 10) {
            ActivityLogger::log('communication', 'missed_call_ignored', 'Exotel call without a usable caller number', ['channel' => 'exotel', 'status' => 'failed', 'details' => ['params' => array_intersect_key($params, array_flip(['CallSid', 'CallTo', 'Direction', 'Created']))]]);

            return null;
        }
        if ($sid !== '' && PhoneVerification::where('call_sid', $sid)->exists()) {
            return PhoneVerification::where('call_sid', $sid)->first();   // Exotel retried the webhook
        }

        $row = PhoneVerification::where('phone', $caller)->where('status', PhoneVerification::STATUS_PENDING)
            ->where('expires_at', '>', now())->latest('id')->first();
        if (! $row) {
            ActivityLogger::log('communication', 'missed_call_unmatched',
                'Missed call from ' . ActivityLogger::phone($caller) . ' — no pending verification for that number',
                ['channel' => 'exotel', 'status' => 'failed', 'member' => Member::where('phone', 'like', '%' . $caller)->value('id'), 'details' => ['call_sid' => $sid]]);

            return null;
        }

        $row->forceFill(['status' => PhoneVerification::STATUS_VERIFIED, 'caller' => $caller, 'call_sid' => $sid ?: null, 'verified_at' => now()])->save();
        ActivityLogger::log('communication', 'missed_call_verified',
            'Number ' . ActivityLogger::phone($caller) . ' verified by missed call (' . $row->purpose . ')',
            ['channel' => 'exotel', 'status' => 'ok', 'member' => $row->member_id, 'subject' => $row, 'details' => ['purpose' => $row->purpose, 'device_uid' => $row->device_uid, 'call_sid' => $sid]]);

        return $row;
    }

    /** Current state for the app's polling; marks an overdue attempt expired. */
    public function status(PhoneVerification $row): PhoneVerification
    {
        if ($row->status === PhoneVerification::STATUS_PENDING && $row->expires_at->isPast()) {
            $row->forceFill(['status' => PhoneVerification::STATUS_EXPIRED])->save();
        }

        return $row;
    }

    /** Exotel account check: lists the Exophones on the account (used by exotel:check). */
    public function exophones(): array
    {
        $c = config('services.exotel');
        $res = Http::withBasicAuth((string) $c['api_key'], (string) $c['api_token'])
            ->withOptions(['verify' => app_ca()])
            ->timeout(15)
            ->get(sprintf('https://%s/v1/Accounts/%s/IncomingPhoneNumbers.json', $c['subdomain'] ?: 'api.exotel.com', $c['sid']));
        abort_unless($res->successful(), 502, 'Exotel answered ' . $res->status() . ': ' . mb_substr($res->body(), 0, 200));

        return collect($res->json('IncomingPhoneNumbers') ?? [])
            ->map(fn ($n) => $n['IncomingPhoneNumber'] ?? $n)
            ->map(fn ($n) => ['number' => $n['PhoneNumber'] ?? null, 'friendly' => $n['FriendlyName'] ?? null, 'sid' => $n['Sid'] ?? null])
            ->all();
    }
}
