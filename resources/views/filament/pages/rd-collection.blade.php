<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex items-center justify-end gap-3">
            {{-- Disabled during ANY Livewire round-trip (saving OR the bond/contract
                 lookup after picking a saver) so a double-click can't post twice. --}}
            <x-filament::button
                type="submit"
                size="lg"
                color="primary"
                icon="heroicon-m-arrow-path"
                wire:loading.attr="disabled">
                <span wire:loading.remove>Save collection</span>
                <span wire:loading>Processing…</span>
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
