<?php

namespace App\Services\Activity;

use App\Models\ActivityLog;
use App\Models\Device;
use App\Models\Member;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The one door for writing activity logs (board 2026-10-31). Never throws: logging must
 * not break the activity it records. Actor, IP and request id are filled in automatically.
 *
 *   ActivityLogger::log('communication', 'push_sent', 'Push to LJW01: Invoice ready', [
 *       'member' => $member, 'channel' => 'push', 'details' => [...], 'status' => 'ok',
 *   ]);
 */
class ActivityLogger
{
    /** Attributes never written into details (secrets / personal numbers). */
    public const HIDDEN = [
        'password', 'remember_token', 'private_key', 'fcm_private_key', 'apns_private_key', 'wifi_pass',
        'otp', 'code', 'token', 'api_token', 'access_token', 'secret', 'client_secret', 'aadhaar', 'pan',
        'bank_acno', 'pairing_code', 'webhook_secret', 'key', 'value',
    ];

    public static ?string $requestId = null;

    /** Set to false in bulk imports to keep the table quiet; model events honour it. */
    public static bool $enabled = true;

    /**
     * @param  array{member?:Member|int|null, branch?:int|null, subject?:Model|null, subject_label?:string|null,
     *               channel?:string|null, status?:string, details?:array|null, actor?:Model|string|null, occurred_at?:Carbon|null}  $opts
     */
    public static function log(string $category, string $action, string $summary, array $opts = []): ?ActivityLog
    {
        if (! self::$enabled) {
            return null;
        }
        try {
            [$actorType, $actorId, $actorName] = self::actor($opts['actor'] ?? null);
            $member = $opts['member'] ?? null;
            $memberId = $member instanceof Member ? $member->id : ($member ? (int) $member : null);
            $subject = $opts['subject'] ?? null;
            $console = app()->runningInConsole() && ! app()->runningUnitTests();
            $request = $console ? null : request();

            return ActivityLog::create([
                'occurred_at' => $opts['occurred_at'] ?? Carbon::now(),
                'category' => $category,
                'action' => Str::limit($action, 60, ''),
                'status' => $opts['status'] ?? 'ok',
                'channel' => $opts['channel'] ?? ($console ? 'console' : ($request?->is('api/*') ? 'api' : 'panel')),
                'summary' => Str::limit($summary, 255, '…'),
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'actor_name' => $actorName ? Str::limit($actorName, 120, '') : null,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => isset($opts['subject_label']) ? Str::limit((string) $opts['subject_label'], 120, '') : ($subject ? self::labelOf($subject) : null),
                'member_id' => $memberId ?? ($subject ? self::memberIdOf($subject) : null),
                'branch_id' => $opts['branch'] ?? ($subject ? self::branchIdOf($subject) : null),
                'details' => isset($opts['details']) ? self::scrub($opts['details']) : null,
                'ip' => $request?->ip(),
                'user_agent' => $request ? Str::limit((string) $request->userAgent(), 255, '') : null,
                'request_id' => self::requestId(),
                'created_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('ActivityLogger failed: ' . $e->getMessage(), ['category' => $category, 'action' => $action]);

            return null;
        }
    }

    /** Run $fn with model-event logging switched off (bulk imports, seeders). */
    public static function silenced(callable $fn): mixed
    {
        $was = self::$enabled;
        self::$enabled = false;
        try {
            return $fn();
        } finally {
            self::$enabled = $was;
        }
    }

    public static function requestId(): string
    {
        return self::$requestId ??= (string) Str::ulid();
    }

    /** @return array{0:?string,1:?int,2:?string} */
    public static function actor(Model|string|null $explicit = null): array
    {
        if (is_string($explicit)) {
            return ['system', null, $explicit];
        }
        // an authenticated user wins even under PHPUnit / queue workers (both "run in console")
        $a = $explicit ?? (auth()->user() ?: (app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->user()));
        if ($a instanceof User) {
            $impersonator = session()->get('impersonator_id');

            return ['admin', $a->id, $a->name . ($impersonator ? ' (as dealer, by admin #' . $impersonator . ')' : '')];
        }
        if ($a instanceof Member) {
            return ['member', $a->id, trim($a->name . ' (' . $a->member_code . ')')];
        }
        if ($a instanceof Device) {
            return ['device', $a->id, $a->name . ' · ' . $a->serial_no];
        }
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return ['system', null, 'console' . (isset($_SERVER['argv'][1]) ? ' ' . $_SERVER['argv'][1] : '')];
        }

        return ['guest', null, null];
    }

    public static function labelOf(Model $m): ?string
    {
        foreach (['invoice_no', 'request_no', 'contract_no', 'return_no', 'transfer_no', 'ref_no', 'qr_code', 'member_code', 'serial_no', 'code', 'ticket_no', 'name', 'title', 'key', 'phone'] as $f) {
            $v = $m->getAttribute($f);
            if (filled($v)) {
                $v = is_array($v) ? ($v['en'] ?? reset($v)) : $v;

                return Str::limit((string) $v, 120, '');
            }
        }

        return '#' . $m->getKey();
    }

    public static function memberIdOf(Model $m): ?int
    {
        if ($m instanceof Member) {
            return $m->id;
        }
        foreach (['member_id', 'customer_member_id', 'reference_member_id'] as $f) {
            if ($m->getAttribute($f)) {
                return (int) $m->getAttribute($f);
            }
        }

        return null;
    }

    public static function branchIdOf(Model $m): ?int
    {
        if ($m instanceof \App\Models\Branch) {
            return $m->id;
        }

        return $m->getAttribute('branch_id') ? (int) $m->getAttribute('branch_id') : null;
    }

    /** Drop secrets / personal numbers from a details array (recursively). */
    public static function scrub(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_string($k) && in_array(strtolower($k), self::HIDDEN, true)) {
                $out[$k] = '[hidden]';
            } elseif (is_array($v)) {
                $out[$k] = self::scrub($v);
            } elseif (is_string($v) && strlen($v) > 2000) {
                $out[$k] = Str::limit($v, 2000, '…');
            } else {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** Phone shown in a log line: 98xxxxxx82. */
    public static function phone(?string $phone): string
    {
        return (string) \App\Support\Mask::phone($phone);
    }
}
