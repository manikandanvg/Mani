{{-- Commission Approval footer (user 2026-11-15): the total of EVERYTHING listed for the chosen
     type / dates / schemes — all pages, not just the rows on screen. --}}
<div class="flex flex-wrap items-center gap-x-8 gap-y-2 border-t-2 border-gray-300 px-4 py-3 text-sm dark:border-white/20">
    <span class="font-bold text-gray-900 dark:text-white">{{ __('Total') }}</span>
    @foreach ($cells as $label => $value)
        <span class="whitespace-nowrap">
            <span class="text-gray-500 dark:text-gray-400">{{ __($label) }}:</span>
            <span class="font-bold text-gray-900 dark:text-white">{{ $value }}</span>
        </span>
    @endforeach
</div>
