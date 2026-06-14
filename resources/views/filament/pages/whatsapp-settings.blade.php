<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center justify-end gap-3">
            <x-filament::button
                type="button"
                color="gray"
                icon="heroicon-m-paper-airplane"
                wire:click="sendTest">
                Send test message
            </x-filament::button>
            <x-filament::button
                type="submit"
                color="primary"
                icon="heroicon-m-check">
                Save settings
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
