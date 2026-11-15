{{-- Support Chat popup (board 2026-11-15): the whole conversation + rich-text reply in a modal. --}}
@livewire(\App\Livewire\SupportChatPanel::class, ['threadId' => $record->id], key('support-chat-' . $record->id))
