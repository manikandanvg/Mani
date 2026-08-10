<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled">
                {{ __('Save commission charges') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
