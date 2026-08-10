{{-- Generic renderer for $sections built by Track / Report / wallet pages:
     each section = ['heading' => …, 'kv' => [label => value]] and/or
     ['columns' => […], 'rows' => [[cell, …]]]. Values are escaped — plain text only. --}}
@foreach ($sections as $section)
    <x-filament::section :heading="$section['heading'] ?? null" :compact="true">
        @if (! empty($section['kv']))
            <dl class="grid grid-cols-2 gap-x-6 gap-y-4 md:grid-cols-4">
                @foreach ($section['kv'] as $label => $value)
                    <div>
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $label }}</dt>
                        <dd class="mt-0.5 text-sm font-semibold text-gray-900 dark:text-white">{{ $value === null || $value === '' ? '—' : $value }}</dd>
                    </div>
                @endforeach
            </dl>
        @endif

        @if (! empty($section['columns']))
            <div class="{{ empty($section['kv']) ? '' : 'mt-4' }} overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 dark:border-white/10">
                            @foreach ($section['columns'] as $col)
                                <th class="px-3 py-2 text-left font-semibold text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ $col }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($section['rows'] as $row)
                            <tr class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                @foreach ($row as $cell)
                                    <td class="px-3 py-2 text-gray-900 dark:text-gray-100 whitespace-nowrap">{{ $cell === null || $cell === '' ? '—' : $cell }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td colspan="{{ count($section['columns']) }}" class="px-3 py-4 text-gray-500 dark:text-gray-400">
                                    {{ __('No records') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
@endforeach
