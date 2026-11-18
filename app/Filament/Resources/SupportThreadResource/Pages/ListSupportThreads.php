<?php

namespace App\Filament\Resources\SupportThreadResource\Pages;

use App\Filament\Resources\SupportThreadResource;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\On;

class ListSupportThreads extends ListRecords
{
    protected static string $resource = SupportThreadResource::class;

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
