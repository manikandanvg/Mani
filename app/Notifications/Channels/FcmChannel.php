<?php

namespace App\Notifications\Channels;

use App\Models\DeviceToken;
use App\Services\Push\ApnsSender;
use App\Services\Push\PushSender;
use Illuminate\Notifications\Notification;

/**
 * Custom notification channel that delivers a notification to the notifiable's
 * registered devices (Phase 4b). Registered as the 'fcm' channel; a notification
 * opts in by listing 'fcm' in via() and exposing toFcm(). Each device row carries
 * its provider: Android tokens go out via FCM, iPhone tokens via native APNs
 * (board 2026-09-21) — one dispatch, both gateways.
 *
 * Pairs with the database channel: the same AppNotification persists to the inbox
 * AND pushes, from one dispatch. Dead tokens reported by either gateway are pruned.
 */
class FcmChannel
{
    public function __construct(protected PushSender $push, protected ApnsSender $apns) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toFcm') || ! method_exists($notifiable, 'deviceTokens')) {
            return;
        }

        $devices = $notifiable->deviceTokens()->get(['token', 'provider']);
        if ($devices->isEmpty()) {
            return;
        }

        /** @var array{title: string, body: string, data: array} $payload */
        $payload = $notification->toFcm($notifiable);
        $title = $payload['title'] ?? '';
        $body = $payload['body'] ?? '';
        $data = $payload['data'] ?? [];

        $apnsTokens = $devices->where('provider', 'apns')->pluck('token')->all();
        $fcmTokens = $devices->where('provider', '!=', 'apns')->pluck('token')->all();

        $stale = [];
        if ($fcmTokens) {
            $stale = array_merge($stale, $this->push->sendToTokens($fcmTokens, $title, $body, $data)['stale'] ?? []);
        }
        if ($apnsTokens) {
            $stale = array_merge($stale, $this->apns->sendToTokens($apnsTokens, $title, $body, $data)['stale'] ?? []);
        }

        // Prune tokens the gateway reported as unregistered so we stop pushing to dead devices.
        if ($stale) {
            DeviceToken::whereIn('token', $stale)->delete();
        }
    }
}
