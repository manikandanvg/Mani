{{-- Generic renderer for $sections built by Track / Report / wallet pages:
     each section = ['heading' => …, 'kv' => [label => value]] and/or
     ['columns' => […], 'rows' => [[cell, …]]]. Values are escaped — plain text only.

     Board 2026-08-11: tables are interactive DATA-TABLES — per-table search,
     click-to-sort (numeric-aware), pagination — powered by the tiny Alpine
     component below. Page-level CSV/Excel/PDF/Print exports live on the page. --}}

@once
<script>
    function lordDataTable() {
        return {
            q: '', page: 1, per: 10, sortCol: null, sortDir: 1, filtered: 0, total: 0, rows: [],
            init() {
                this.rows = Array.from(this.$refs.tbody.querySelectorAll('tr[data-row]'));
                this.total = this.rows.length;
                this.filtered = this.total;
                this.apply();
            },
            cellVal(tr, i) {
                const t = (tr.children[i]?.textContent ?? '').trim();
                const n = parseFloat(t.replace(/[₹,%\s,]/g, '').replace(/,/g, ''));
                return isNaN(n) || t === '' || /[a-zA-Z]{3,}/.test(t) ? t.toLowerCase() : n;
            },
            sortBy(i) {
                if (this.sortCol === i) { this.sortDir *= -1; } else { this.sortCol = i; this.sortDir = 1; }
                this.page = 1;
                this.apply();
            },
            search() { this.page = 1; this.apply(); },
            apply() {
                let list = this.rows.filter(tr => !this.q || tr.textContent.toLowerCase().includes(this.q.toLowerCase()));
                if (this.sortCol !== null) {
                    const i = this.sortCol, d = this.sortDir, val = tr => this.cellVal(tr, i);
                    list = list.slice().sort((a, b) => { const x = val(a), y = val(b); return (x < y ? -1 : x > y ? 1 : 0) * d; });
                }
                this.filtered = list.length;
                const maxPage = Math.max(1, Math.ceil(list.length / this.per));
                if (this.page > maxPage) this.page = maxPage;
                const start = (this.page - 1) * this.per;
                this.rows.forEach(tr => { tr.style.display = 'none'; });
                list.forEach((tr, i) => {
                    tr.parentNode.appendChild(tr);
                    if (i >= start && i < start + this.per) tr.style.display = '';
                });
                const empty = this.$refs.tbody.querySelector('tr[data-empty]');
                if (empty) empty.style.display = this.filtered === 0 ? '' : 'none';
            },
            get pages() { return Math.max(1, Math.ceil(this.filtered / this.per)); },
            get fromRow() { return this.filtered === 0 ? 0 : (this.page - 1) * this.per + 1; },
            get toRow() { return Math.min(this.page * this.per, this.filtered); },
        };
    }
</script>
@endonce

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
            <div class="{{ empty($section['kv']) ? '' : 'mt-4' }}" x-data="lordDataTable()">
                @if (count($section['rows'] ?? []) > 5)
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <input type="text" x-model="q" @input.debounce.300ms="search()"
                               placeholder="{{ __('Search…') }}"
                               class="rounded-lg border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-white/10 dark:bg-white/5 dark:text-white"
                               style="max-width:220px" />
                        <select x-model.number="per" @change="page=1;apply()"
                                class="rounded-lg border-gray-300 text-sm shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                            <option value="10">10</option>
                            <option value="25">25</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                        </select>
                    </div>
                @endif

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 dark:border-white/10">
                                @foreach ($section['columns'] as $ci => $col)
                                    <th class="cursor-pointer select-none whitespace-nowrap px-3 py-2 text-left font-semibold text-gray-700 hover:text-primary-600 dark:text-gray-300"
                                        @click="sortBy({{ $ci }})">
                                        {{ $col }}
                                        <span x-show="sortCol === {{ $ci }}" x-cloak class="text-xs" x-text="sortDir === 1 ? '▲' : '▼'"></span>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody x-ref="tbody">
                            @forelse ($section['rows'] as $row)
                                <tr data-row class="border-b border-gray-100 last:border-0 dark:border-white/5">
                                    @foreach ($row as $cell)
                                        <td class="whitespace-nowrap px-3 py-2 text-gray-900 dark:text-gray-100">{{ $cell === null || $cell === '' ? '—' : $cell }}</td>
                                    @endforeach
                                </tr>
                            @empty
                            @endforelse
                            <tr data-empty style="{{ count($section['rows'] ?? []) ? 'display:none' : '' }}">
                                <td colspan="{{ count($section['columns']) }}" class="px-3 py-4 text-gray-500 dark:text-gray-400">
                                    {{ __('No records') }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                @if (count($section['rows'] ?? []) > 5)
                    <div class="mt-2 flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <span x-text="`{{ __('Showing') }} ${fromRow}–${toRow} {{ __('of') }} ${filtered}`"></span>
                        <span class="inline-flex items-center gap-1">
                            <button type="button" class="rounded-md border border-gray-300 px-2 py-1 disabled:opacity-40 dark:border-white/10"
                                    :disabled="page <= 1" @click="page--; apply()">‹</button>
                            <span x-text="`${page} / ${pages}`"></span>
                            <button type="button" class="rounded-md border border-gray-300 px-2 py-1 disabled:opacity-40 dark:border-white/10"
                                    :disabled="page >= pages" @click="page++; apply()">›</button>
                        </span>
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>
@endforeach
