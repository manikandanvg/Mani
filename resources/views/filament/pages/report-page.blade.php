<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit="run">
            {{ $this->form }}

            <div class="mt-4 flex items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-o-play" wire:loading.attr="disabled">
                    {{ __('Run report') }}
                </x-filament::button>

                @if ($ran && ! empty($sections))
                    <x-filament::button color="gray" icon="heroicon-o-arrow-down-tray" wire:click="downloadCsv" wire:loading.attr="disabled">
                        {{ __('Download CSV') }}
                    </x-filament::button>
                @endif
            </div>
        </form>

        @if ($ran && empty($sections))
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing to report for the chosen filters.') }}</div>
            </x-filament::section>
        @endif

        @include('filament.pages.partials.data-sections')
    </div>
</x-filament-panels::page>
