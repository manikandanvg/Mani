<div class="space-y-3">
    {{-- Who / status --}}
    <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
        <div class="font-semibold text-gray-900 dark:text-white">
            {{ \App\Filament\Resources\SupportThreadResource::ownerLabel($thread) }}
            @if ($thread->subject)
                <span class="font-normal text-gray-500 dark:text-gray-400">· {{ $thread->subject }}</span>
            @endif
        </div>
        <x-filament::badge :color="$thread->status === 'open' ? 'warning' : 'gray'">
            {{ $thread->status === 'open' ? __('Open') : __('Closed') }}
        </x-filament::badge>
    </div>

    {{-- Transcript — polls so the user's messages appear while the desk is typing. --}}
    <div
        wire:poll.5s
        x-data
        x-init="$el.scrollTop = $el.scrollHeight"
        @chat-updated.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
        class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
        style="max-height: 42vh; min-height: 180px; overflow-y: auto;"
    >
        @forelse ($messages as $m)
            @php $isSupport = $m->sender === 'support'; @endphp
            <div class="mb-2 flex {{ $isSupport ? 'justify-end' : 'justify-start' }}">
                <div class="icl-chat-bubble max-w-[80%] rounded-2xl px-4 py-2 text-sm leading-snug"
                     style="{{ $isSupport ? 'background:#ab222f;color:#fff' : 'background:rgba(0,0,0,0.06)' }}">
                    <div class="mb-0.5 text-[11px] opacity-70">
                        {{ $isSupport ? ('HQ' . ($m->supportUser ? ' · ' . $m->supportUser->name : '')) : \App\Filament\Resources\SupportThreadResource::ownerLabel($thread) }}
                        · {{ optional($m->created_at)->timezone(config('app.timezone'))->format('d M, h:i A') }}
                    </div>
                    @if ($isSupport && filled($m->body_html))
                        {!! \App\Livewire\SupportChatPanel::clean($m->body_html) !!}
                    @else
                        {!! nl2br(e($m->body)) !!}
                    @endif
                </div>
            </div>
        @empty
            <div class="text-sm text-gray-400">{{ __('No messages yet.') }}</div>
        @endforelse
    </div>

    {{-- Rich-text reply --}}
    <form wire:submit="send" class="space-y-2">
        {{ $this->form }}

        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-gray-400">
                {{ __('The user receives every reply as a push notification and in the app inbox.') }}
            </p>
            <div class="flex items-center gap-2">
                <x-filament::button color="gray" type="button" wire:click="toggleStatus" wire:loading.attr="disabled">
                    {{ $thread->status === 'open' ? __('Close chat') : __('Reopen') }}
                </x-filament::button>
                <x-filament::button type="submit" icon="heroicon-o-paper-airplane" wire:loading.attr="disabled">
                    {{ __('Send reply') }}
                </x-filament::button>
            </div>
        </div>
    </form>

    <x-filament-actions::modals />
</div>
