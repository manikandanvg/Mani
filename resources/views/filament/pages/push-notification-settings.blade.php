<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center gap-3">
            <x-filament::button type="submit" icon="heroicon-o-check" wire:loading.attr="disabled">
                {{ __('Save settings') }}
            </x-filament::button>

            <x-filament::button color="gray" icon="heroicon-o-paper-airplane" wire:click="sendTest" wire:loading.attr="disabled">
                {{ __('Send test notification') }}
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
