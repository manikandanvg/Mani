<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center justify-end gap-3">
            {{-- Disabled during ANY round-trip so a double-click can never allocate twice --}}
            <x-filament::button
                type="submit"
                size="lg"
                color="success"
                icon="heroicon-m-truck"
                wire:loading.attr="disabled"
                wire:confirm="Add this stock to the selected branch now? It takes effect immediately.">
                Allocate stock to branch
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
