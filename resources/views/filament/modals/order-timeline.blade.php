@php
    /** Road map of a customized order — used in the order View popup. Expects the
     *  infolist record (BranchOrderRequest) via $getRecord(); every hop sees the same list. */
    $rec = $record ?? (isset($getRecord) ? $getRecord() : null);
    $events = $rec?->events()->with(['branch', 'toBranch', 'user'])->orderBy('id')->get() ?? collect();
    $colour = fn (string $a) => match ($a) {
        'rejected' => '#dc2626', 'accepted', 'billed' => '#16a34a', 'delivered' => '#2563eb', 'coins_captured' => '#b45309', default => '#6b7280',
    };
@endphp

@if ($events->isEmpty())
    <div style="color:#9ca3af;font-size:.85rem">{{ __('No travel recorded yet.') }}</div>
@else
    <ol style="list-style:none;margin:0;padding:0;border-left:2px solid #e5e7eb">
        @foreach ($events as $e)
            @php $meta = (array) ($e->meta ?? []); @endphp
            <li style="position:relative;padding:0 0 .9rem 1.1rem">
                <span style="position:absolute;left:-7px;top:.3rem;width:12px;height:12px;border-radius:50%;background:{{ $colour($e->action) }}"></span>
                <div style="font-weight:600;font-size:.88rem">{{ __($e->label()) }}</div>
                <div style="font-size:.8rem;color:#374151">
                    @if ($e->branch){{ $e->branch->name }}@endif
                    @if ($e->toBranch) → <b>{{ $e->toBranch->name }}</b>@endif
                    @if ($e->user) · {{ $e->user->name }}@endif
                    · {{ $e->created_at?->timezone('Asia/Kolkata')->format('d M Y H:i') }}
                </div>
                @if (! empty($meta['quote_extra']) || ! empty($meta['delivery_date']) || ! empty($meta['coin_pickup_on']) || ! empty($meta['invoice_no']) || isset($meta['coins']))
                    <div style="font-size:.78rem;color:#6b7280">
                        @if (! empty($meta['delivery_date'])) Delivery {{ \Illuminate\Support\Carbon::parse($meta['delivery_date'])->format('d M Y') }} · @endif
                        @if (! empty($meta['coin_pickup_on'])) Coin pick-up {{ $meta['coin_pickup_on'] }} · @endif
                        @if (! empty($meta['quote_extra'])) Extra quote ₹{{ \App\Support\Money::group((float) $meta['quote_extra']) }} · @endif
                        @if (isset($meta['coins'])) {{ rtrim(rtrim(number_format((float) $meta['coins'], 4), '0'), '.') }} coin(s), {{ number_format((float) ($meta['grams'] ?? 0), 3) }} g · @endif
                        @if (! empty($meta['invoice_no'])) Invoice {{ $meta['invoice_no'] }} · @endif
                    </div>
                @endif
                @if ($e->note)
                    <div style="font-size:.78rem;color:#6b7280;font-style:italic">{{ $e->note }}</div>
                @endif
            </li>
        @endforeach
    </ol>
@endif
