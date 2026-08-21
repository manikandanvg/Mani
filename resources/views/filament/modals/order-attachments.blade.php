@php
    /** Payment-proof gallery for a stock order — used inside the Approve modal
     *  and the order View popup. Expects $record (BranchOrderRequest). */
    $rec = $record ?? (isset($getRecord) ? $getRecord() : null);
    $atts = $rec?->attachments ?? collect();
@endphp

@if ($atts->isEmpty())
    <div style="color:#9ca3af;font-size:.85rem">{{ __('No payment receipt attached.') }}</div>
@else
    <div style="display:flex;flex-wrap:wrap;gap:.6rem">
        @foreach ($atts as $att)
            <a href="{{ $att->url() }}" target="_blank" rel="noopener"
               style="display:block;width:9rem;text-decoration:none;border:1px solid #e5e7eb;border-radius:.6rem;overflow:hidden;background:#fff">
                @if ($att->isImage())
                    <img src="{{ $att->url() }}" alt="{{ $att->original_name }}"
                         style="width:100%;height:6.5rem;object-fit:cover;display:block">
                @else
                    <div style="height:6.5rem;display:flex;align-items:center;justify-content:center;background:#f3f4f6">
                        <span style="font-weight:800;color:#ab222f;letter-spacing:.05em">PDF</span>
                    </div>
                @endif
                <div style="padding:.35rem .5rem;font-size:.68rem;color:#374151;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    {{ $att->original_name ?: basename($att->path) }}
                </div>
            </a>
        @endforeach
    </div>
@endif
