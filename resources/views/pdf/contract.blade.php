@php
    use App\Support\Translatable;

    $plan    = $bond->plan;
    $member  = $bond->member;
    $branch  = $bond->branch;

    $planName = $plan ? (Translatable::pick($plan->name) ?: $plan->code) : '—';
    // Bond amounts are stored in INR base; show the contract in the branch's own currency.
    $fx       = $branch?->fxRateToBase() ?? 1.0;
    $sym      = $branch?->currencySymbol() ?: '₹';
    $isInr    = strtoupper($branch?->currency_code ?: 'INR') === 'INR';
    $amount   = round((float) ($bond->epin_value ?: $bond->value) / $fx, 2);   // branch currency
    $duration = (int) ($plan->validity_months ?: $plan->level_com_duration ?: 12);
    $start    = $bond->bond_date;
    $end      = $start ? $start->copy()->addMonthsNoOverflow($duration) : null;
    $fmt      = fn ($n) => $sym . ' ' . number_format((float) $n, 2);

    $contractBody = $contractBody ?? '';
    $contractNo   = $contractNo ?? $bond->invoice_no;
    $bg           = storage_path('app/contract/contract-bg.jpg');

    // Vertical offsets (mm) calibrated to the background image's printed markers.
    $yMeta     = 8;     // contract no / date (top-right, under header)
    $yParties  = 58;    // dealer + customer block (inside the decorative frame)
    $yDetails  = 106;   // under "CONTRACT DETAILS"
    $yBenefits = 168;   // under "BENEFITS"
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 0; }
        @font-face { font-family:'tamil'; font-style:normal; font-weight:normal;
            src: url("{{ storage_path('fonts/NotoSansTamil-Regular.ttf') }}") format("truetype"); }
        * { font-family: DejaVu Sans, 'tamil', sans-serif; }
        body { margin:0; padding:0; color:#3a1f1f; font-size:11px; }
        .bg { position:absolute; top:0; left:0; width:210mm; height:297mm; }
        .abs { position:absolute; left:14mm; width:182mm; }
        table { border-collapse:collapse; }
        .muted { color:#5b3a2e; font-size:9.5px; }
        .ctitle { color:#ab222f; font-weight:bold; font-size:11px; }
        .det td { padding:3px 4px; font-size:11px; }
        .det .k { color:#3a1f1f; width:34%; }
        .det .v { border-bottom:1px solid #ab222f; color:#ab222f; font-weight:bold; }
    </style>
</head>
<body>
    {{-- full-page branded background (header, MOU banner, section markers, terms, footer) --}}
    <img class="bg" src="{{ $bg }}">

    {{-- contract no as barcode + date (top-right under the header band) --}}
    <div class="abs" style="top:{{ $yMeta }}mm; left:24mm; width:182mm; text-align:right; color:#111827;">
        @if (!empty($barcode))
            <img src="{{ $barcode }}" style="height:11mm; width:auto;"><br>
        @endif
        <span style="font-weight:bold; font-size:12px;">Contract No: {{ $contractNo }}</span>
        <span class="muted">&nbsp;·&nbsp; Date: {{ optional($start)->format('d-m-Y') }}</span>
    </div>

    {{-- AUTHORIZED DEALER  |  CUSTOMER --}}
    <div class="abs" style="top:{{ $yParties }}mm;">
        <table style="width:100%">
            <tr>
                <td style="width:52%; vertical-align:top; padding-right:8px;">
                    <div class="ctitle">AUTHORIZED DEALER</div>
                    <div style="font-weight:bold; font-size:12px;">{{ $branch->name ?? 'Head Office' }}</div>
                    <div class="muted">{{ $branch->address ?? '' }}{{ $branch->city ? ', '.$branch->city : '' }} {{ $branch->pincode ?? '' }}</div>
                    <div class="muted">Phone: {{ $branch->phone ?? '—' }}</div>
                    <div class="muted">GSTN: {{ $branch->gst_no ?? '—' }}</div>
                </td>
                <td style="width:48%; vertical-align:top; padding-left:8px;">
                    <div class="ctitle">CUSTOMER</div>
                    <div style="font-weight:bold; font-size:12px;">{{ $member->name ?? '—' }}</div>
                    <div class="muted">({{ $member->member_code ?? '' }})</div>
                    <div class="muted">{{ $member->address ?? '' }}{{ $member->city ? ', '.$member->city : '' }} {{ $member->pincode ?? '' }}</div>
                    <div class="muted">Phone: {{ $member->phone ?? '—' }}</div>
                    <div class="muted">PAN: {{ $member->pan ?: '-NA-' }}</div>
                </td>
            </tr>
        </table>
    </div>

    {{-- CONTRACT DETAILS (under the printed marker) --}}
    <div class="abs" style="top:{{ $yDetails }}mm; left:24mm; width:162mm;">
        <table class="det" style="width:100%">
            <tr><td class="k">CONTRACT NAME</td><td>:</td><td class="v">{{ strtoupper($planName) }}</td></tr>
            <tr><td class="k">CONTRACT AMOUNT</td><td>:</td><td class="v">{{ $fmt($amount) }}</td></tr>
            <tr><td class="k">AMOUNT IN WORD</td><td>:</td><td class="v">{{ $isInr ? strtoupper(inr_words($amount)) : $fmt($amount) }}</td></tr>
            <tr><td class="k">CONTRACT DURATION</td><td>:</td><td class="v">{{ $duration }} MONTHS</td></tr>
            <tr><td class="k">CONTRACT START DATE</td><td>:</td><td class="v">{{ optional($start)->format('d-m-Y') }}</td></tr>
            <tr><td class="k">CONTRACT END DATE</td><td>:</td><td class="v">{{ optional($end)->format('d-m-Y') }}</td></tr>
        </table>
    </div>

    {{-- BENEFITS (per-plan content, under the printed marker) --}}
    <div class="abs" style="top:{{ $yBenefits }}mm; left:16mm; width:178mm; font-size:9.5px; line-height:1.4;">
        @if (trim($contractBody) !== '')
            {!! $contractBody !!}
        @else
            <table style="width:100%; font-size:10px;">
                <tr><td>Contract value</td><td style="text-align:right; color:#ab222f; font-weight:bold;">{{ $fmt($amount) }}</td></tr>
            </table>
        @endif
    </div>
</body>
</html>
