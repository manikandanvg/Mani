<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit="lookup">
            {{ $this->form }}

            <div class="mt-4">
                <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled">
                    {{ __('Track') }}
                </x-filament::button>
            </div>
        </form>

        @if ($searched && empty($sections))
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('No matching record found.') }}</div>
            </x-filament::section>
        @endif

        @include('filament.pages.partials.data-sections')
    </div>
</x-filament-panels::page>
