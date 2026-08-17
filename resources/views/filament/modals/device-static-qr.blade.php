{{-- Static-QR card for the Devices → Static QR modal (board 2026-08-12, web item 9).
     Shown inside a Filament modal: branch name in brand maroon, device identity,
     the printable QR large, and a scan hint. Close-only — nothing to submit. --}}
<div style="display:flex; justify-content:center; padding:0.25rem 0 0.5rem;">
    <div style="width:100%; max-width:24rem; text-align:center; background:#ffffff; border:1px solid #e6ad46; border-radius:1rem; padding:1.5rem 1.25rem; box-shadow:0 6px 18px rgba(171,34,47,0.10);">

        <div style="font-size:1.3rem; font-weight:800; color:#ab222f; line-height:1.3;">
            {{ $device->branch?->name ?? __('No branch assigned') }}
        </div>

        <div style="margin-top:0.3rem; font-size:0.85rem; color:#78716c;">
            {{ $device->name }} &middot; {{ $device->serial_no }}
        </div>

        <div style="margin:1.1rem auto 0.9rem; display:inline-block; padding:0.7rem; background:#ffffff; border:3px solid #ab222f; border-radius:0.9rem;">
            <img
                src="{{ $qrUrl }}"
                alt="{{ __('L-BOX static QR') }}"
                style="display:block; width:230px; height:230px; max-width:100%; image-rendering:pixelated;"
            />
        </div>

        <div style="font-size:0.85rem; font-weight:600; color:#57534e;">
            {{ __('Scan in the LORDICL app → Withdraw at branch') }}
        </div>

        <div style="margin-top:0.55rem; padding-top:0.55rem; border-top:1px dashed #e6ad46; font-size:0.72rem; color:#a8a29e;">
            {{ __('Print this QR on the box — distributors scan it to withdraw their wallet at this branch.') }}
        </div>
    </div>
</div>
