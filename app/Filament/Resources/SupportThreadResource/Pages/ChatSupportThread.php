<?php

namespace App\Filament\Resources\SupportThreadResource\Pages;

use App\Filament\Resources\SupportThreadResource;
use App\Models\SupportThread;
use App\Services\Support\SupportService;
use Filament\Resources\Pages\Page;

/**
 * Live chat view for one support thread (board 2026-08-11 6.3.16): the transcript
 * polls every few seconds, replies send inline without closing anything, and the
 * user gets each reply via inbox + push through SupportService.
 */
class ChatSupportThread extends Page
{
    protected static string $resource = SupportThreadResource::class;

    protected static string $view = 'filament.pages.support-chat';

    public SupportThread $record;

    public string $body = '';

    public function mount(int|string|SupportThread $record): void
    {
        // Livewire's implicit binding usually hands us the resolved model already;
        // fall back to a lookup when only the key arrives.
        $this->record = $record instanceof SupportThread ? $record : SupportThread::findOrFail($record);
        app(SupportService::class)->markSupportRead($this->record);
    }

    public function getTitle(): string
    {
        return 'Chat · ' . SupportThreadResource::ownerLabel($this->record);
    }

    public function send(): void
    {
        $body = trim($this->body);
        if ($body === '') {
            return;
        }

        app(SupportService::class)->supportReply($this->record, $body, auth()->id());
        app(SupportService::class)->markSupportRead($this->record);
        $this->body = '';
        $this->record->refresh();
        $this->dispatch('chat-updated');
    }

    public function toggleStatus(): void
    {
        $this->record->update(['status' => $this->record->status === 'open' ? 'closed' : 'open']);
        $this->record->refresh();
    }
}
