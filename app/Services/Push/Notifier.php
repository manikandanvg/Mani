<?php

namespace App\Services\Push;

use App\Models\Member;
use App\Notifications\AppNotification;
use Illuminate\Support\Facades\Log;

/**
 * One front door for every app notification (board 2026-08-11: WhatsApp = OTP only;
 * all acknowledgements go push + inbox). Each send writes an inbox row (read/unread
 * in the app) AND pushes to every registered device via FCM — both through
 * AppNotification, so the two can never say different things.
 *
 * Never throws: a notification failure must not break billing/redeeming/settling.
 *
 * Category vocabulary (the app groups + icons by these):
 *   rates | network | commission | rd | invoice | contract | qr | redeem
 *   | wallet | settlement | order | news | system
 */
class Notifier
{
    public static function to(?Member $member, string $category, string $title, string $body = '', ?string $route = null, array $data = []): void
    {
        if (! $member) {
            return;
        }

        try {
            $member->notify(new AppNotification($category, $title, $body, $route, $data));
        } catch (\Throwable $e) {
            Log::warning("Notify [{$category}] to member #{$member->id} failed: " . $e->getMessage());
        }
    }

    /**
     * Alert Head Office (board 2026-08-11: "admin mobile" + panel): every active
     * super_admin/admin user gets a Filament PANEL BELL notification, and — where
     * their login is mapped to a member account — an app push + inbox entry too.
     */
    public static function admins(string $title, string $body = '', ?string $url = null, string $category = 'system'): void
    {
        try {
            $users = \App\Models\User::where('status', 'active')
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'admin']))
                ->with('memberAccount')
                ->get();

            foreach ($users as $user) {
                $bell = \Filament\Notifications\Notification::make()
                    ->title($title)
                    ->body($body);
                if ($url) {
                    $bell->actions([
                        \Filament\Notifications\Actions\Action::make('open')->label('Open')->url($url),
                    ]);
                }
                // notifyNow: Filament queues database notifications by default, and this
                // host runs no queue worker — deliver the bell synchronously instead.
                $user->notifyNow($bell->toDatabase($user));

                self::to($user->memberAccount, $category, $title, $body);
            }
        } catch (\Throwable $e) {
            Log::warning('Admin notification failed: ' . $e->getMessage());
        }
    }

    /**
     * Fan a notification out to every app-registered member (those with at least one
     * device token — i.e. members who have logged into the app). Chunked; used for
     * price changes and announcements.
     *
     * @return int members notified
     */
    public static function broadcast(string $category, string $title, string $body = '', ?string $route = null, array $data = []): int
    {
        $count = 0;
        Member::where('status', 'active')
            ->whereHas('deviceTokens')
            ->chunkById(200, function ($members) use (&$count, $category, $title, $body, $route, $data) {
                foreach ($members as $member) {
                    self::to($member, $category, $title, $body, $route, $data);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Fan out to a CUSTOM member query (board 2026-08-12 item 7: meeting schedules
     * go to the targeted audience — e.g. only District Admin & above). The caller
     * builds the query; this only adds the active + app-registered constraints.
     *
     * @return int members notified
     */
    public static function toQuery(\Illuminate\Database\Eloquent\Builder $query, string $category, string $title, string $body = '', ?string $route = null, array $data = []): int
    {
        $count = 0;
        $query->where('status', 'active')
            ->whereHas('deviceTokens')
            ->chunkById(200, function ($members) use (&$count, $category, $title, $body, $route, $data) {
                foreach ($members as $member) {
                    self::to($member, $category, $title, $body, $route, $data);
                    $count++;
                }
            });

        return $count;
    }
}
