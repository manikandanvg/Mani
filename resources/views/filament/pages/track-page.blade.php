<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit="lookup">
            {{ $this->form }}

            <div class="mt-4">
                <x-filament::button type="submit" icon="heroicon-o-magnifying-glass" wire:loading.attr="disabled">
                    {{ __('Track') }}
                </x-filament::button>
            </div>
        </form>

        @if ($searched && empty($sections))
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('No matching record found.') }}</div>
            </x-filament::section>
        @endif

        @if (! empty($sections))
            <div class="flex flex-wrap items-center gap-2">
                <x-filament::button size="sm" color="gray" icon="heroicon-m-table-cells" wire:click="exportCsv" wire:loading.attr="disabled">CSV</x-filament::button>
                <x-filament::button size="sm" color="gray" icon="heroicon-m-document-chart-bar" wire:click="exportXlsx" wire:loading.attr="disabled">Excel</x-filament::button>
                <x-filament::button size="sm" color="gray" icon="heroicon-m-document-arrow-down" wire:click="exportPdf" wire:loading.attr="disabled">PDF</x-filament::button>
                <x-filament::button size="sm" color="gray" icon="heroicon-m-printer" wire:click="exportPrint" wire:loading.attr="disabled">{{ __('Print') }}</x-filament::button>
            </div>
        @endif

        @include('filament.pages.partials.data-sections')
    </div>
</x-filament-panels::page>
