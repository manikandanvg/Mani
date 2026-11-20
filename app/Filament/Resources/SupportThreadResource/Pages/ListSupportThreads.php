<?php

namespace App\Filament\Resources\SupportThreadResource\Pages;

use App\Filament\Resources\SupportThreadResource;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\On;

class ListSupportThreads extends ListRecords
{
    protected static string $resource = SupportThreadResource::class;

    /**
     * User 2026-11-20: people who got no answer raised the same thing again as a new chat. One
     * button folds each person's open chats (same customer = same name and phone) into one.
     */
    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('merge_duplicates')
                ->label('Merge duplicate chats')
                ->icon('heroicon-o-arrows-pointing-in')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Merge duplicate chats')
                ->modalDescription(function () {
                    $groups = app(\App\Services\Support\SupportService::class)->duplicateGroups();
                    if ($groups->isEmpty()) {
                        return 'No customer has more than one open chat.';
                    }

                    return $groups->count() . ' customer(s) have ' . $groups->sum->count() . ' open chats between them. '
                        . 'Each customer\'s chats become ONE chat with all the messages in the order they were written; '
                        . 'the customer sees the same single chat in the app. No message is sent. This cannot be undone.';
                })
                ->action(function () {
                    $r = app(\App\Services\Support\SupportService::class)->mergeAllDuplicates();
                    \Filament\Notifications\Notification::make()->success()
                        ->title($r['removed'] ? "{$r['removed']} duplicate chat(s) merged — {$r['people']} customer(s) now have one chat each" : 'Nothing to merge')
                        ->send();
                    $this->resetPage();
                }),
        ];
    }

    /**
     * A chat was closed / reopened INSIDE the popup (SupportChatPanel). The list did not know, so
     * the chat stayed in the "Open chats" list until the page was reloaded. Shut the popup — the
     * chat no longer belongs to the filtered list it was opened from — and draw the list again.
     */
    #[On('support-thread-changed')]
    public function threadChanged(): void
    {
        if (filled($this->mountedTableActions)) {
            $this->unmountTableAction();
        }
        $this->resetPage();
    }
}
