<?php

namespace App\Notifications\Channels;

use App\Models\DeviceToken;
use App\Services\Push\PushSender;
use Illuminate\Notifications\Notification;

/**
 * Custom notification channel that delivers a notification to the notifiable's
 * registered devices via FCM (Phase 4b). Registered as the 'fcm' channel; a
 * notification opts in by listing 'fcm' in via() and exposing toFcm().
 *
 * Pairs with the database channel: the same AppNotification persists to the inbox
 * AND pushes, from one dispatch. Dead tokens reported by FCM are pruned here.
 */
class FcmChannel
{
    public function __construct(protected PushSender $push) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm')) {
            return;
        }

        $tokens = $notifiable->routeNotificationFor('fcm', $notification);
        if (empty($tokens)) {
            return;
        }

        /** @var array{title: string, body: string, data: array} $payload */
        $payload = $notification->toFcm($notifiable);

        $result = $this->push->sendToTokens(
            $tokens,
            $payload['title'] ?? '',
            $payload['body'] ?? '',
            $payload['data'] ?? [],
        );

        // Prune tokens FCM reported as unregistered so we stop pushing to dead devices.
        if (! empty($result['stale'])) {
            DeviceToken::whereIn('token', $result['stale'])->delete();
        }
    }
}
