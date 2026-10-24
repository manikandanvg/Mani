@php
    // Logo embedded as a data URI — dompdf runs with remote fetching off.
    $logoFile = public_path('images/logo-pdf.png');
    $logo = is_file($logoFile) ? 'data:image/png;base64,'.base64_encode(file_get_contents($logoFile)) : null;
@endphp
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 27mm 12mm 16mm 12mm; }
    /* Tamil glyphs: Noto Sans Tamil (storage/fonts) as the fallback after DejaVu, as the contract PDF does. */
    @font-face { font-family: 'tamil'; font-style: normal; font-weight: normal;
        src: url("{{ storage_path('fonts/NotoSansTamil-Regular.ttf') }}") format("truetype"); }
    @font-face { font-family: 'tamil'; font-style: normal; font-weight: bold;
        src: url("{{ storage_path('fonts/NotoSansTamil-Regular.ttf') }}") format("truetype"); }
    body, td, th, h2, div { font-family: 'DejaVu Sans', sans-serif; }
    .ta { font-family: 'tamil', 'DejaVu Sans', sans-serif; }
    body { font-size: 9.5px; color: #222; }

    /* Brand band on every page */
    /* No <table> inside the fixed band: a table in a fixed element breaks dompdf's row pagination
       (every wrapped row of the data tables lands on its own page). Plain floats instead. */
    .band { position: fixed; top: -21mm; left: 0; right: 0; height: 16mm; background: #ab222f; color: #fff; padding: 0 4mm; border-radius: 8px; }
    .band .logo { float: left; width: 13mm; height: 13mm; margin-top: 1.5mm; }
    .band img { height: 13mm; width: 13mm; }
    .band .brand { float: left; font-size: 17px; font-weight: bold; letter-spacing: 1px; padding-left: 3mm; margin-top: 5mm; color: #fff; }
    .band .right { float: right; text-align: right; padding-top: 3mm; }
    .band .title { font-size: 12px; font-weight: bold; color: #fff; }
    .band .meta { font-size: 8px; color: #f4e8cf; }

    /* Footer with page numbers */
    .foot { position: fixed; bottom: -10mm; left: 0; right: 0; height: 6mm; border-top: 0.5pt solid #c9b48a; color: #777; font-size: 8px; padding-top: 1.5mm; }
    /* NO floats inside the fixed footer: floated spans in a fixed element make dompdf lay every wrapped table row out page-high. */
    .foot .l { position: absolute; left: 0; top: 1.5mm; }
    .foot .r { position: absolute; right: 0; top: 1.5mm; }
    .foot .r:after { content: "Page " counter(page); }

    h2 { font-size: 11.5px; margin: 12px 0 4px; padding: 5px 9px; color: #7a1520; background: #f4e8cf; border-left: 3pt solid #ab222f; border-radius: 6px; }
    h2.first { margin-top: 0; }
    .break { page-break-before: always; }

    table.kv { border-collapse: collapse; margin: 2px 0 4px; }
    table.kv td { padding: 3px 8px; border: 0.5pt solid #ddd; vertical-align: top; }
    table.kv td.k { color: #666; background: #fbf8f1; width: 190px; }

    table.data { width: 100%; border-collapse: collapse; margin-top: 4px; table-layout: auto; }
    table.data th { background: #f4e8cf; color: #5a2a2e; text-align: left; padding: 4px 6px; border: 0.5pt solid #c9b48a; font-weight: bold; }
    /* Responsive: every cell may wrap so a wide table always fits the paper; numbers stay on one line. */
    table.data td { padding: 3px 6px; border: 0.5pt solid #d6d0c4; vertical-align: top; white-space: normal; word-wrap: break-word; overflow-wrap: break-word; }
    table.data th { white-space: normal; word-wrap: break-word; }
    table.data.wide { font-size: 8px; }
    table.data.wide th, table.data.wide td { padding: 2px 4px; }
    table.data.dense { font-size: 7px; }
    .cont { margin-top: 6px; font-size: 8px; color: #7a1520; font-style: italic; }
    table.data.dense th, table.data.dense td { padding: 2px 3px; }
    /* rows never split across pages; the page-high rows dompdf produced came from tables wider than ~12 columns (now split into groups) */
    table.data tr:nth-child(even) td { background: #fbf8f1; }
    table.data td.num { text-align: right; white-space: nowrap; }
    table.data td.empty { color: #888; text-align: center; padding: 6px; }
    thead { display: table-header-group; }
</style>
</head>
<body>
    <div class="band">
        @if ($logo)<div class="logo"><img src="{{ $logo }}" alt=""></div>@endif
        <div class="brand">LORD JEWELLER</div>
        <div class="right">
            <div class="title">{{ $title }}</div>
            <div class="meta">Generated {{ $generatedAt }}</div>
        </div>
    </div>
    <div class="foot">
        <span class="l">Lord Jeweller · {{ $title }} · {{ $generatedAt }}</span>
        <span class="r"></span>
    </div>

    @foreach ($sections as $section)
        @php $break = ! $loop->first && ! empty($section['page_break']); @endphp
        @if (! empty($section['heading']))
            <h2 class="{{ $loop->first ? 'first' : '' }} {{ $break ? 'break' : '' }}">{!! \App\Support\PdfText::html($section['heading']) !!}</h2>
        @elseif ($break)
            <div class="break"></div>
        @endif

        @if (! empty($section['kv']))
            <table class="kv">
                @foreach ($section['kv'] as $label => $value)
                    <tr><td class="k">{!! \App\Support\PdfText::html($label) !!}</td><td>{!! $value === null || $value === '' ? '—' : \App\Support\PdfText::html($value) !!}</td></tr>
                @endforeach
            </table>
        @endif

        @if (! empty($section['columns']))
            @php
                // dompdf lays every row out page-high once a table is wider than ~12 columns, whatever the
                // text. Wider tables are printed as column groups that repeat the first two (key) columns.
                $allCols = array_values($section['columns']);
                $maxCols = 12;
                $keyCount = count($allCols) > $maxCols ? 2 : 0;
                $groups = count($allCols) > $maxCols
                    ? array_map(fn ($chunk) => array_merge(range(0, $keyCount - 1), $chunk), array_chunk(range($keyCount, count($allCols) - 1), $maxCols - $keyCount))
                    : [range(0, count($allCols) - 1)];
            @endphp
            @foreach ($groups as $gi => $idx)
                @php
                    $cols = count($idx);
                    $density = $cols >= 11 ? 'dense' : ($cols >= 8 ? 'wide' : '');
                    // Deterministic column widths (dompdf's automatic sizing collapses into page-high rows once
                    // wrapping text columns carry enough text): each column gets at least its longest unbreakable
                    // token and at most ~40 characters of its longest cell, then the row is normalised to 100%.
                    $need = [];
                    foreach ($idx as $ci) {
                        $longest = mb_strlen((string) $allCols[$ci]);
                        $token = 0;
                        foreach (array_slice($section['rows'] ?? [], 0, 400) as $r) {
                            $cell = (string) (array_values($r)[$ci] ?? '');
                            $longest = max($longest, mb_strlen($cell));
                            foreach (preg_split('/\s+/u', $cell) as $word) {
                                $token = max($token, mb_strlen($word));
                            }
                        }
                        $need[$ci] = max(4, $token + 1, min($longest, 40));
                    }
                    $sum = max(1, array_sum($need));
                @endphp
                @if ($gi > 0)<div class="cont">{{ __('continued') }} · {{ __('columns') }} {{ $idx[$keyCount] + 1 }}–{{ end($idx) + 1 }}</div>@endif
                <table class="data {{ $density }}" style="table-layout: fixed; width: 100%">
                    <thead><tr>@foreach ($idx as $ci)<th style="width: {{ round($need[$ci] / $sum * 100, 2) }}%">{!! \App\Support\PdfText::html($allCols[$ci]) !!}</th>@endforeach</tr></thead>
                    <tbody>
                        @forelse ($section['rows'] as $row)
                            @php $row = array_values($row); @endphp
                            <tr>@foreach ($idx as $ci)@php $cell = $row[$ci] ?? ''; @endphp<td class="{{ is_string($cell) && preg_match('/^[₹\-]?[\d,]+(\.\d+)?( g)?$/u', trim($cell)) ? 'num' : (is_string($cell) && mb_strlen($cell) > 24 ? 'wrap' : '') }}">{!! $cell === null || $cell === '' ? '—' : \App\Support\PdfText::html($cell) !!}</td>@endforeach</tr>
                        @empty
                            <tr><td class="empty" colspan="{{ $cols }}">No records</td></tr>
                        @endforelse
                    </tbody>
                </table>
            @endforeach
        @endif
    @endforeach
</body>
</html>
