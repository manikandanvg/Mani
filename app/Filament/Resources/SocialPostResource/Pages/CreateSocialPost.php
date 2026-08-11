<?php

namespace App\Filament\Resources\SocialPostResource\Pages;

use App\Filament\Resources\SocialPostResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateSocialPost extends CreateRecord
{
    // NB: the meeting-scheduled broadcast lives in CreateMeeting; this page handles news.

    protected static string $resource = SocialPostResource::class;

    /** News/announcement push (board 2026-08-11) — skipped for private or future-dated posts. */
    protected function afterCreate(): void
    {
        $post = $this->getRecord();
        if ($post->visibility === 'private'
            || ($post->published_at && $post->published_at->isFuture())) {
            return;
        }

        $n = \App\Services\Push\Notifier::broadcast(
            'news',
            $post->title ?: 'News from Lord Jeweller',
            (string) str(strip_tags((string) $post->body))->limit(120),
            route: '/feed/' . $post->id,
        );

        \Filament\Notifications\Notification::make()
            ->title("Announcement published — {$n} member(s) notified")
            ->success()->send();
    }
}
