<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * The one notification the app inbox renders (Phase 4). Domain code dispatches it
 * via `$member->notify(new AppNotification(...))`; it persists to the `notifications`
 * table (database channel) with a stable, app-friendly shape:
 *
 *   category  — groups/filters & picks an icon (order|wallet|commission|system|...)
 *   title     — bold headline line
 *   body      — supporting line
 *   route     — optional in-app deep link the row taps through to (e.g. "/orders/12")
 *   data      — extra structured payload (ids etc.) for the client
 *
 * FCM push (Phase 4b) will add an 'fcm' channel that reuses this same payload, so the
 * inbox record and the push notification never drift apart.
 */
class AppNotification extends Notification
{
    public function __construct(
        public string $category,
        public string $title,
        public string $body = '',
        public ?string $route = null,
        public array $data = [],
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        // Always persist to the inbox; also push to FCM (no-op until configured).
        return ['database', 'fcm'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'title' => $this->title,
            'body' => $this->body,
            'route' => $this->route,
            'data' => $this->data,
        ];
    }

    /**
     * Payload for the FCM channel — same headline/body as the inbox, plus a flat
     * string data bag the app uses to deep-link (category + route + caller data).
     *
     * @return array{title: string, body: string, data: array<string, mixed>}
     */
    public function toFcm(object $notifiable): array
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'data' => array_merge($this->data, [
                'category' => $this->category,
                'route' => (string) $this->route,
            ]),
        ];
    }
}
