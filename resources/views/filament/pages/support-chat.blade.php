<x-filament-panels::page>
    <div class="space-y-4">
        {{-- Transcript — polls so user messages appear live while the desk types. --}}
        <div
            wire:poll.5s
            x-data
            x-init="$el.scrollTop = $el.scrollHeight"
            @chat-updated.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
            class="rounded-xl border border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 p-4 overflow-y-auto"
            style="max-height: 60vh; min-height: 240px;"
        >
            @forelse ($this->record->messages()->orderBy('created_at')->get() as $m)
                @php $isSupport = $m->sender === 'support'; @endphp
                <div class="flex {{ $isSupport ? 'justify-end' : 'justify-start' }} mb-2">
                    <div
                        class="max-w-[75%] rounded-2xl px-4 py-2 text-sm leading-snug"
                        style="{{ $isSupport ? 'background:#ab222f;color:#fff' : 'background:rgba(0,0,0,0.06)' }}"
                    >
                        <div class="text-[11px] opacity-70 mb-0.5">
                            {{ $isSupport ? 'HQ' : \App\Filament\Resources\SupportThreadResource::ownerLabel($this->record) }}
                            · {{ optional($m->created_at)->timezone(config('app.timezone'))->format('d M, h:i A') }}
                        </div>
                        {!! nl2br(e($m->body)) !!}
                    </div>
                </div>
            @empty
                <div class="text-gray-400 text-sm">{{ __('No messages yet.') }}</div>
            @endforelse
        </div>

        {{-- Composer --}}
        <form wire:submit="send" class="flex items-end gap-2">
            <div class="flex-1">
                <x-filament::input.wrapper>
                    <textarea
                        wire:model="body"
                        rows="2"
                        maxlength="4000"
                        placeholder="{{ __('Type a reply…') }}"
                        class="fi-input block w-full border-none bg-transparent px-3 py-2 text-sm outline-none"
                        @keydown.enter.prevent="if (!$event.shiftKey) $wire.send()"
                    ></textarea>
                </x-filament::input.wrapper>
            </div>
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane" wire:loading.attr="disabled">
                {{ __('Send') }}
            </x-filament::button>
            <x-filament::button color="gray" wire:click="toggleStatus" wire:loading.attr="disabled">
                {{ $this->record->status === 'open' ? __('Close chat') : __('Reopen') }}
            </x-filament::button>
        </form>

        <p class="text-xs text-gray-400">
            {{ __('Enter sends · Shift+Enter for a new line. The user receives every reply as a push notification and in their app inbox.') }}
        </p>
    </div>
</x-filament-panels::page>
