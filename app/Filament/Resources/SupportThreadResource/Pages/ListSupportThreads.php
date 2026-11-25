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
                    $support = app(\App\Services\Support\SupportService::class);
                    $open = $support->duplicateGroups();
                    $all = $support->duplicateGroups(true);
                    if ($all->isEmpty()) {
                        return 'No customer has more than one chat.';
                    }

                    return $open->count() . ' customer(s) have more than one OPEN chat; counting closed chats too, '
                        . $all->count() . ' customer(s) have ' . $all->sum->count() . ' chats between them. '
                        . 'Each customer\'s chats become ONE chat with all the messages in the order they were written; '
                        . 'the customer sees the same single chat in the app. No message is sent. This cannot be undone.';
                })
                // Board 2026-11-25: the same person still showed several times under "Closed chats",
                // and an old closed chat came back as a second open one.
                ->form([
                    \Filament\Forms\Components\Checkbox::make('with_closed')
                        ->label('Also merge each customer\'s CLOSED chats (the whole history in one chat)')
                        ->helperText('Ticked: one customer = one chat everywhere. A customer with an open chat gets their old closed chats folded into it; a customer with only closed chats keeps one closed chat. Unticked: only customers with two or more OPEN chats are merged.')
                        ->default(true),
                ])
                ->action(function (array $data) {
                    $r = app(\App\Services\Support\SupportService::class)->mergeAllDuplicates((bool) ($data['with_closed'] ?? true));
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
