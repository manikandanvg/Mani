<x-filament-panels::page>
    <form wire:submit="generate" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center justify-end gap-3">
            <x-filament::button
                type="submit"
                size="lg"
                color="primary"
                icon="heroicon-m-document-check">
                Generate Invoice
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
