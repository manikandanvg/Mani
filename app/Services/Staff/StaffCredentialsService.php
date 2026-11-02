<?php

namespace App\Services\Staff;

use App\Models\User;
use App\Notifications\Channels\FcmChannel;
use App\Services\Activity\ActivityLogger;
use App\Services\Push\Notifier;
use Illuminate\Support\Str;

/**
 * Staff → "Send login details" (user 2026-11-02; board: WhatsApp is for OTP only, everything
 * else goes by FCM). Passwords are stored hashed, so the button sets a NEW temporary password
 * and sends the login (branch, e-mail, password, sign-in link) as an app push + inbox entry to
 * the login's member account. A login without a member account cannot be reached, so its
 * password is left alone. The password is never written to the activity log.
 */
class StaffCredentialsService
{
    /** @return array{password:string,member_code:string,devices:int} */
    public function send(User $user, ?int $byUserId = null): array
    {
        $member = $user->memberAccount;
        abort_unless($member, 422, $user->email . ' has no member account (member code) to push to — nothing sent, password unchanged.');

        $password = Str::password(10, letters: true, numbers: true, symbols: false, spaces: false);
        $user->forceFill(['password' => $password])->save();   // 'hashed' cast

        $branch = $user->branch;
        $loginUrl = rtrim((string) config('app.url'), '/') . '/admin/login';
        $lines = ['Name: ' . $user->name];
        if ($branch) {
            $lines[] = 'Branch: ' . $branch->pickLabel();
        }
        $lines[] = 'Login: ' . $user->email;
        $lines[] = 'Password: ' . $password;
        $lines[] = 'Sign in: ' . $loginUrl;
        $lines[] = 'Please change the password after signing in.';

        Notifier::to($member, 'system', 'Your login details', implode("\n", $lines), null, ['sensitive' => true]);
        $devices = (int) (FcmChannel::$lastDelivery['devices'] ?? 0);

        ActivityLogger::log('user', 'staff_credentials_sent',
            sprintf('Login details sent to %s (%s) as app push + inbox to %s (%d device%s)', $user->email, $user->name, $member->member_code, $devices, $devices === 1 ? '' : 's'), [
                'subject' => $user, 'member' => $member->id,
                'details' => ['member_code' => $member->member_code, 'devices' => $devices, 'password_reset' => true],
            ]);

        return ['password' => $password, 'member_code' => $member->member_code, 'devices' => $devices];
    }
}
