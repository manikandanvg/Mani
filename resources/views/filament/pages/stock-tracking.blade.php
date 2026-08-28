<x-filament-panels::page>
    <form wire:submit.prevent class="space-y-4">
        {{ $this->form }}
    </form>

    <?php $c = $this->chart(); ?>

    @if (! $c)
        <x-filament::section>
            <div style="color:#6b7280">{{ __('Pick a branch to see its daily stock.') }}</div>
        </x-filament::section>
    @else
        <x-filament::section>
            <x-slot name="heading">{{ $c['branch']['name'] }} · {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $c['month'])->format('F Y') }}</x-slot>
            <x-slot name="description">
                {{ __(':n shortfall day(s) this month', ['n' => $c['shortfall_days']]) }} ·
                {{ __('bar height = stock ÷ Opening for the worst item of the day; red = below Opening; grey = no snapshot (branch closed).') }}
            </x-slot>

            <div style="overflow-x:auto">
                <div style="min-width:36rem">
                    <div style="position:relative;display:flex;align-items:flex-end;gap:3px;height:180px;border-bottom:1px solid #e5e7eb;padding-top:14px">
                        <div style="position:absolute;left:0;right:0;bottom:50%;border-top:2px dashed #e6ad46"></div>
                        <span style="position:absolute;right:0;bottom:calc(50% + 4px);font-size:11px;color:#b45309">{{ __('Opening') }}</span>
                        @foreach ($c['series'] as $d)
                            <?php
                                $h = $d['checked'] ? ($d['ratio'] === null ? 50 : min(100, round($d['ratio'] * 50))) : 6;
                                $bg = ! $d['checked'] ? '#e5e7eb' : ($d['short'] ? '#ab222f' : '#2f7d4f');
                                $tip = $d['checked']
                                    ? ($d['product'] ?? '') . ': ' . rtrim(rtrim(number_format($d['quantity'], 3), '0'), '.') . ' / ' . ($d['opening'] !== null ? rtrim(rtrim(number_format($d['opening'], 3), '0'), '.') : '—')
                                    : __('No snapshot');
                            ?>
                            <div title="{{ $d['date'] }} — {{ $tip }}" style="flex:1;height:{{ $h }}%;background:{{ $bg }};border-radius:2px 2px 0 0;min-width:6px"></div>
                        @endforeach
                    </div>
                    <div style="display:flex;gap:3px;margin-top:4px">
                        @foreach ($c['series'] as $d)
                            <div style="flex:1;text-align:center;font-size:10px;color:{{ $d['short'] ? '#ab222f' : '#9ca3af' }};min-width:6px">{{ $d['day'] }}</div>
                        @endforeach
                    </div>
                </div>
            </div>

            @if (count($c['products']))
                <div style="margin-top:1rem;display:flex;flex-wrap:wrap;gap:.5rem">
                    @foreach ($c['products'] as $p)
                        <span style="font-size:12px;padding:.2rem .6rem;border-radius:999px;background:{{ $p['short_days'] ? '#fbe3e5' : '#e6f4ea' }};color:{{ $p['short_days'] ? '#ab222f' : '#2f7d4f' }}">
                            {{ $p['name'] }} · {{ $p['short_days'] }} {{ __('short day(s)') }}
                        </span>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
