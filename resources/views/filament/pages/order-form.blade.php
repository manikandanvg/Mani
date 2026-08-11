<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center justify-end gap-3">
            {{-- Disabled during ANY round-trip so a double-click can never double-order --}}
            <x-filament::button
                type="submit"
                size="lg"
                color="primary"
                icon="heroicon-m-paper-airplane"
                wire:loading.attr="disabled">
                Submit order to HQ
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
