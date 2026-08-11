<x-filament-panels::page>
    <div class="space-y-6">
        <form wire:submit="run">
            {{ $this->form }}

            <div class="mt-4 flex items-center gap-3">
                <x-filament::button type="submit" icon="heroicon-o-play" wire:loading.attr="disabled">
                    {{ __('Run report') }}
                </x-filament::button>

                @if ($ran && ! empty($sections))
                    <x-filament::button color="gray" size="sm" icon="heroicon-m-table-cells" wire:click="exportCsv" wire:loading.attr="disabled">CSV</x-filament::button>
                    <x-filament::button color="gray" size="sm" icon="heroicon-m-document-chart-bar" wire:click="exportXlsx" wire:loading.attr="disabled">Excel</x-filament::button>
                    <x-filament::button color="gray" size="sm" icon="heroicon-m-document-arrow-down" wire:click="exportPdf" wire:loading.attr="disabled">PDF</x-filament::button>
                    <x-filament::button color="gray" size="sm" icon="heroicon-m-printer" wire:click="exportPrint" wire:loading.attr="disabled">{{ __('Print') }}</x-filament::button>
                    @if (method_exists($this, 'exportGstr1'))
                        <x-filament::button color="warning" size="sm" icon="heroicon-m-document-check" wire:click="exportGstr1" wire:loading.attr="disabled">
                            {{ __('GSTR-1 Excel') }}
                        </x-filament::button>
                    @endif
                @endif
            </div>
        </form>

        @if ($ran && empty($sections))
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('Nothing to report for the chosen filters.') }}</div>
            </x-filament::section>
        @endif

        @include('filament.pages.partials.data-sections')
    </div>
</x-filament-panels::page>
