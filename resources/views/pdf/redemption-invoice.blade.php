@php
    use App\Support\Translatable;

    $member = $invoice->member;
    $plan   = $invoice->bond?->plan;
    $fmt    = fn ($n) => number_format((float) $n, 2);
    $wt     = fn ($n) => rtrim(rtrim(number_format((float) $n, 3), '0'), '.');
    $totalGst = (float) $invoice->cgst + (float) $invoice->sgst;

    // Optional foreign-currency reference. INR stays the legal invoice currency (GST
    // documents must be in INR); we only ADD an approximate converted grand total.
    $fxCode    = \App\Support\Money::current();
    $baseCode  = \App\Support\Money::base()?->code ?? 'INR';
    $showFx    = strtoupper($fxCode) !== strtoupper($baseCode);
    $fxSymbol  = \App\Support\Money::currency($fxCode)?->symbol ?? '';
    $fxGrand   = \App\Support\Money::convert((float) $invoice->grand_total, $fxCode);

    // Full-page branded letterhead (header / watermark / bank box / T&C / footer are baked in).
    $bg = storage_path('app/invoice/invoice-bg.jpg');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin:0; padding:0; color:#1f2937; font-size:9px; }
        .bg { position:absolute; top:0; left:0; width:210mm; height:297mm; }
        /* Letterhead geometry (measured): red header band ends ~30.5mm, bank box starts ~204.5mm. */
        /* Upper band: starts a few mm below the header, runs through the tax summary. */
        .overlay { position:absolute; top:34mm; left:7mm; width:196mm; }
        /* Foot band: declaration + signatures, pinned just above the printed bank box. */
        .overlay-foot { position:absolute; top:174mm; left:7mm; width:196mm; }
        .muted { color:#6b7280; }
        .red { color:#ab222f; }
        .b { font-weight:bold; }
        .center { text-align:center; }
        .right { text-align:right; }
        .title { font-size:14px; font-weight:bold; letter-spacing:3px; color:#ab222f; }
        table { border-collapse:collapse; width:100%; }
        .meta td { border:0.5px solid #9ca3af; padding:3px 5px; vertical-align:top; font-size:9px; }
        .meta .lbl { color:#6b7280; font-size:8px; display:block; margin-bottom:1px; }
        .items th { border:0.5px solid #9ca3af; background:#ab222f; color:#fff; padding:4px 3px; font-size:8.5px; }
        .items td { border:0.5px solid #9ca3af; padding:3px 4px; font-size:8.5px; }
        .items tfoot td { font-weight:bold; background:#fbf6ee; padding:3px 4px; }
        .sum td, .sum th { border:0.5px solid #9ca3af; padding:3px 5px; font-size:8.5px; }
        .sign td { border:0.5px solid #9ca3af; padding:6px; font-size:8.5px; }
    </style>
</head>
<body>
    <img class="bg" src="{{ $bg }}">

    {{-- Top barcode of the invoice number (top-right cream area of the header) --}}
    @if (!empty($barcode))
        <div style="position:absolute; top:5mm; left:150mm; width:53mm; text-align:center;">
            <img src="{{ $barcode }}" style="height:10mm; width:50mm;"><br>
            <span style="font-size:7px; letter-spacing:1px; color:#374151;">{{ $invoice->invoice_no }}</span>
        </div>
    @endif

    {{-- TAX INVOICE — sits on the GSTN/HUID/CIN line at the header foot --}}
    <div style="position:absolute; top:25mm; left:120mm; width:83mm; text-align:right;" class="title">TAX INVOICE</div>

    <div class="overlay">

        {{-- Seller (branch) + invoice meta + bill-to --}}
        <table class="meta">
            <tr>
                <td style="width:50%;" rowspan="2">
                    <span class="b red" style="font-size:11px;">{{ $seller['name'] }}</span><br>
                    <span class="muted">{{ $seller['address'] }}</span><br>
                    @if ($seller['phone'])<span class="muted">Phone: {{ $seller['phone'] }}</span><br>@endif
                    @if ($seller['gst_no'])<span class="b">GSTIN: {{ $seller['gst_no'] }}</span>@endif
                </td>
                <td style="width:25%;"><span class="lbl">Invoice Number</span><span class="b">{{ $invoice->invoice_no }}</span></td>
                <td style="width:25%;"><span class="lbl">Invoice Date</span>{{ optional($invoice->invoice_date)->format('d-m-Y') }}</td>
            </tr>
            <tr>
                <td><span class="lbl">Reference No</span>{{ $invoice->reference_no ?: '—' }}</td>
                <td><span class="lbl">Referrer</span>{{ $invoice->referrer_name ?: '—' }}</td>
            </tr>
            <tr>
                <td style="width:50%;" rowspan="2">
                    <span class="lbl">Invoice To</span>
                    <span class="b">{{ $member?->name ?? '—' }}</span>@if ($member?->member_code) ({{ $member->member_code }})@endif<br>
                    @if ($member?->phone)<span class="muted">Phone: {{ $member->phone }}</span><br>@endif
                    @if ($plan)<span class="muted">Plan: {{ Translatable::pick($plan->name) ?: $plan->code }}</span><br>@endif
                    @if ($invoice->bond)<span class="muted">Bond/Contract: {{ $invoice->bond->invoice_no }}</span><br>@endif
                    <span class="b">GST: {{ $invoice->buyer_gst ?: '-NA-' }}</span>
                </td>
                <td><span class="lbl">Payment Mode</span><span style="text-transform:uppercase;">{{ $invoice->payment_mode }}</span></td>
                <td><span class="lbl">Payment Reference</span>{{ $invoice->payment_reference ?: '—' }}</td>
            </tr>
            <tr>
                <td><span class="lbl">Gold Market Price</span>₹{{ $fmt($invoice->gold_rate) }}/gm</td>
                <td><span class="lbl">Silver Market Price</span>₹{{ $fmt($invoice->silver_rate) }}/gm</td>
            </tr>
        </table>

        {{-- Line items --}}
        <table class="items" style="margin-top:4px;">
            <thead>
                <tr>
                    <th style="width:6%;">S.No</th>
                    <th style="width:33%;">Description of Goods</th>
                    <th style="width:11%;">HSN</th>
                    <th style="width:9%;">Wt (g)</th>
                    <th style="width:8%;">Qty</th>
                    <th style="width:11%;">Rate ₹</th>
                    <th style="width:10%;">Making ₹</th>
                    <th style="width:12%;" class="right">Amount ₹</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $i => $l)
                    <tr>
                        <td class="center">{{ $i + 1 }}</td>
                        <td style="text-transform:uppercase;">{{ $l->description }}</td>
                        <td class="center">{{ $l->hsn_code ?: '7113' }}</td>
                        <td class="right">{{ $wt($l->unit_weight) }}</td>
                        <td class="right">{{ $wt($l->quantity) }}</td>
                        <td class="right">{{ $fmt($l->rate) }}</td>
                        <td class="right">{{ $fmt((float) $l->making + (float) $l->wastage) }}</td>
                        <td class="right b">{{ $fmt((float) $l->amount + (float) $l->making + (float) $l->wastage) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="7" class="right">Taxable Value</td>
                    <td class="right">{{ $fmt($invoice->taxable_total) }}</td>
                </tr>
                <tr>
                    <td colspan="7" class="right">CGST + SGST</td>
                    <td class="right">{{ $fmt($totalGst) }}</td>
                </tr>
                <tr>
                    <td colspan="7" class="right b red" style="font-size:11px;">GRAND TOTAL</td>
                    <td class="right b red" style="font-size:11px;">₹{{ $fmt($invoice->grand_total) }}</td>
                </tr>
                @if ($showFx)
                <tr>
                    <td colspan="7" class="right muted" style="font-size:9px;">Approx. in {{ strtoupper($fxCode) }}</td>
                    <td class="right muted" style="font-size:9px;">{{ $fxSymbol }}{{ number_format($fxGrand, 2) }}</td>
                </tr>
                @endif
                <tr>
                    <td colspan="8" style="text-transform:uppercase;"><span class="muted">Amount Chargeable (in words):</span> <span class="b">{{ $amountWords }}</span></td>
                </tr>
            </tfoot>
        </table>

        {{-- HSN-wise tax summary --}}
        <table class="sum" style="margin-top:4px;">
            <thead>
                <tr>
                    <th rowspan="2" style="width:18%;">HSN/SAC</th>
                    <th rowspan="2" style="width:18%;">Taxable Value</th>
                    <th colspan="2" class="center">Central Tax</th>
                    <th colspan="2" class="center">State Tax</th>
                    <th rowspan="2" style="width:16%;">Total Tax</th>
                </tr>
                <tr>
                    <th class="center">Rate</th><th class="center">Amount</th>
                    <th class="center">Rate</th><th class="center">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($hsn as $h)
                    <tr>
                        <td>{{ $h['hsn'] }}</td>
                        <td class="right">{{ $fmt($h['taxable']) }}</td>
                        <td class="center">{{ $fmt($h['rate'] / 2) }}%</td>
                        <td class="right">{{ $fmt($h['cgst']) }}</td>
                        <td class="center">{{ $fmt($h['rate'] / 2) }}%</td>
                        <td class="right">{{ $fmt($h['sgst']) }}</td>
                        <td class="right">{{ $fmt($h['cgst'] + $h['sgst']) }}</td>
                    </tr>
                @endforeach
                <tr class="b">
                    <td>Total</td>
                    <td class="right">{{ $fmt($invoice->taxable_total) }}</td>
                    <td></td><td class="right">{{ $fmt($invoice->cgst) }}</td>
                    <td></td><td class="right">{{ $fmt($invoice->sgst) }}</td>
                    <td class="right">{{ $fmt($totalGst) }}</td>
                </tr>
            </tbody>
        </table>

        {{-- Tax amount in words + declaration (flows right under the tax summary) --}}
        <table class="sign" style="margin-top:4px;">
            <tr>
                <td style="width:100%;">
                    <span class="muted b">Tax Amount (in words):</span> {{ $taxWords }}<br>
                    <span class="b">Declaration:</span> We declare that this invoice shows the actual price of the goods described and that all particulars are true and correct.
                </td>
            </tr>
        </table>

    </div>

    {{-- Foot band: QR codes + signatures, pinned just above the printed bank box --}}
    <div class="overlay-foot">
        <table style="border:0;">
            <tr>
                <td style="border:0; width:55%; vertical-align:top;">
                    @if (!empty($qrInvoice))
                        <img src="{{ $qrInvoice }}" style="width:16mm; height:16mm;">
                    @endif
                    @if (!empty($qrPlay))
                        <img src="{{ $qrPlay }}" style="width:16mm; height:16mm; margin-left:8mm;">
                    @endif
                    <br>
                    <span style="font-size:7.5px; color:#6b7280;">Scan to download invoice</span>
                    <span style="font-size:7.5px; color:#6b7280; margin-left:6mm;">Get it on Play Store</span>
                    <br><br><span class="b">Customer Seal &amp; Signature</span>
                </td>
                <td style="border:0; width:45%; vertical-align:bottom;" class="right b red">
                    For {{ $company['legal_name'] }}<br><br><br>
                    <span class="muted b" style="color:#374151;">Authorised Signatory</span>
                </td>
            </tr>
        </table>
    </div>
</body>
</html>
