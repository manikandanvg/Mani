<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center justify-end">
            <x-filament::button
                type="submit"
                color="primary"
                icon="heroicon-m-check">
                Save preferences
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
