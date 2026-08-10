<div style="text-align:center;padding:1rem;">
    <img src="{{ $imageUrl }}" alt="Redeemable Stock QR"
         style="width:240px;height:240px;image-rendering:pixelated;border:1px solid #f0e6cf;border-radius:.5rem;padding:.5rem;background:#fff;" />

    <div style="margin-top:1rem;font-size:.95rem;line-height:1.6;">
        <div style="font-weight:700;color:#ab222f;letter-spacing:.04em;">{{ $qr->qr_code }}</div>
        <div>Worth <strong>₹ {{ number_format((float) $qr->cash_worth, 2) }}</strong>
            @if ($qr->gram_worth !== null)
                · <strong>{{ number_format((float) $qr->gram_worth, 3) }} gm</strong>
            @endif
        </div>
        <div style="text-transform:uppercase;font-size:.8rem;color:#6b7280;">Mode: {{ $qr->qr_mode }}</div>
        <div style="margin-top:.5rem;">
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

    <p style="margin-top:1rem;font-size:.8rem;color:#9ca3af;">
        An OTP to the registered mobile is required to complete redemption. Purchase-plan QRs redeem only at the billing branch; savings QRs at any Lord Jeweller dealer.
    </p>
</div>
