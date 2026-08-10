<x-filament-panels::page>
    <div class="space-y-6">
        @php($counts = $this->getStageCounts())
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ([
                'pending' => ['Placed', 'text-gray-500'],
                'confirmed' => ['Confirmed', 'text-sky-500'],
                'packed' => ['Packed', 'text-amber-500'],
                'shipped' => ['Shipped', 'text-indigo-500'],
                'delivered' => ['Delivered', 'text-green-600'],
                'cancelled' => ['Cancelled', 'text-red-500'],
            ] as $stage => [$label, $color])
                <x-filament::section :compact="true">
                    <div class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __($label) }}</div>
                    <div class="mt-1 text-2xl font-bold {{ $color }}">{{ $counts[$stage] ?? 0 }}</div>
                </x-filament::section>
            @endforeach
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
