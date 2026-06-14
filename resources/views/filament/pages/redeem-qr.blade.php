<x-filament-panels::page>
    <div class="space-y-6">
        {{ $this->form }}

        @if ($ctx !== null)
            <div class="flex justify-end">
                <x-filament::button
                    wire:click="redeem"
                    wire:loading.attr="disabled"
                    size="xl"
                    color="primary"
                    icon="heroicon-o-check-circle"
                >
                    Redeem &amp; raise tax invoice
                </x-filament::button>
            </div>
        @endif
    </div>
</x-filament-panels::page>
