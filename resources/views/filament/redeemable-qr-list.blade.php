{{-- Every redeemable QR on a bond: the billing QR plus each renewal QR (P209 mints
     one per renewal, P208 one at first renewal). Scrolls when the list grows. --}}
<div style="max-height:70vh;overflow-y:auto;">
    @if ($qrs->isEmpty())
        <p style="text-align:center;padding:2rem 1rem;color:#6b7280;">
            No QR minted yet — this plan issues its single gold QR at the <strong>first renewal</strong>.
        </p>
    @endif

    @foreach ($qrs as $i => $item)
        @php($qr = $item['qr'])
        <div style="text-align:center;padding:1rem;{{ $i > 0 ? 'border-top:1px dashed #e5e7eb;' : '' }}">
            <div style="font-weight:700;font-size:.8rem;letter-spacing:.05em;text-transform:uppercase;color:#6b7280;margin-bottom:.5rem;">
                {{ $item['label'] }}
            </div>

            <img src="{{ $item['imageUrl'] }}" alt="Redeemable Stock QR"
                 style="width:180px;height:180px;image-rendering:pixelated;border:1px solid #f0e6cf;border-radius:.5rem;padding:.5rem;background:#fff;" />

            <div style="margin-top:.75rem;font-size:.95rem;line-height:1.6;">
                <div style="font-weight:700;color:#ab222f;letter-spacing:.04em;">{{ $qr->qr_code }}</div>
                <div>Worth <strong>₹ {{ number_format((float) $qr->cash_worth, 2) }}</strong>
                    @if ($qr->gram_worth !== null)
                        · <strong>{{ number_format((float) $qr->gram_worth, 3) }} gm</strong>
                    @endif
                </div>
                <div style="margin-top:.4rem;">
                    <span style="display:inline-block;padding:.15rem .6rem;border-radius:999px;font-size:.75rem;font-weight:600;
                        {{ $qr->qr_sent ? 'background:#dcfce7;color:#166534;' : 'background:#fef3c7;color:#92400e;' }}">
                        {{ $qr->qr_sent ? 'Sent on WhatsApp' : 'Not yet sent' }}
                    </span>
                    <span style="display:inline-block;padding:.15rem .6rem;border-radius:999px;font-size:.75rem;font-weight:600;
                        {{ $qr->status === 'redeemed' ? 'background:#e0e7ff;color:#3730a3;' : 'background:#f3f4f6;color:#374151;' }}">
                        {{ ucfirst($qr->status) }}
                    </span>
                </div>
            </div>
        </div>
    @endforeach

    <p style="margin-top:.5rem;text-align:center;font-size:.8rem;color:#9ca3af;">
        {{ $redeemNote ?? 'Scan to redeem at any Lord Jeweller dealer. An OTP to the registered mobile is required to complete redemption.' }}
    </p>
</div>
