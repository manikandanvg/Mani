<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center justify-end gap-3">
            <x-filament::button type="submit" size="lg" color="primary" icon="heroicon-m-check" wire:loading.attr="disabled">
                <span wire:loading.remove>{{ __('Save bank details') }}</span>
                <span wire:loading>{{ __('Saving…') }}</span>
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
