<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit="submit">
            {{ $this->form }}

            <div class="mt-4">
                <x-filament::button type="submit" icon="heroicon-o-banknotes" wire:loading.attr="disabled">
                    {{ __('Submit withdrawal request') }}
                </x-filament::button>
            </div>
        </form>

        @include('filament.pages.partials.data-sections')
    </div>
</x-filament-panels::page>
