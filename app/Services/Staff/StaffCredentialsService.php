<?php

namespace App\Services\Staff;

use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Services\Push\Notifier;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Support\Str;

/**
 * Staff → "Send login details" (user 2026-11-02): passwords are stored hashed, so the button
 * sets a NEW temporary password and sends the login (branch, e-mail, password, sign-in link)
 * two ways — WhatsApp text to the staff phone (branch phone as fallback) and an app push +
 * inbox entry to the login's member account, when it has one. The old password stops
 * working at once. The password is never written to the activity log.
 */
class StaffCredentialsService
{
    /** @return array{password:string,whatsapp:bool,whatsapp_note:string,push:bool,phone:?string} */
    public function send(User $user, ?int $byUserId = null): array
    {
        $password = Str::password(10, letters: true, numbers: true, symbols: false, spaces: false);
        $user->forceFill(['password' => $password])->save();   // 'hashed' cast

        $branch = $user->branch;
        $phone = trim((string) ($user->phone ?: $branch?->phone));
        $loginUrl = rtrim((string) config('app.url'), '/') . '/admin/login';
        $lines = [
            'Lord Jeweller — your login details',
            'Name: ' . $user->name,
        ];
        if ($branch) {
            $lines[] = 'Branch: ' . $branch->pickLabel();
        }
        $lines[] = 'Login: ' . $user->email;
        $lines[] = 'Password: ' . $password;
        $lines[] = 'Sign in: ' . $loginUrl;
        $lines[] = 'Please change the password after signing in.';
        $message = implode("\n", $lines);

        $wa = ['ok' => false, 'message' => 'No phone number on the login or its branch.'];
        if ($phone !== '') {
            $wa = app(WhatsappSender::class)->sendText($phone, $message, sensitive: true);
        }

        $member = $user->memberAccount;
        $push = false;
        if ($member) {
            Notifier::to($member, 'system', 'Your login details', $message, null, ['sensitive' => true]);
            $push = true;
        }

        ActivityLogger::log('user', 'staff_credentials_sent',
            sprintf('Login details sent to %s (%s): WhatsApp %s, app %s', $user->email, $user->name, ($wa['ok'] ?? false) ? 'sent to ' . ActivityLogger::phone($phone) : 'NOT sent', $push ? 'push + inbox' : 'no member account'), [
                'subject' => $user, 'member' => $member?->id,
                'details' => ['whatsapp' => (bool) ($wa['ok'] ?? false), 'gateway' => $wa['message'] ?? null, 'push' => $push, 'password_reset' => true],
            ]);

        return [
            'password' => $password,
            'whatsapp' => (bool) ($wa['ok'] ?? false),
            'whatsapp_note' => (string) ($wa['message'] ?? ''),
            'push' => $push,
            'phone' => $phone !== '' ? $phone : null,
        ];
    }
}
