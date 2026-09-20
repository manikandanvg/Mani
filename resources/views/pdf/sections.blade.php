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
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; color: #222; }

    /* Brand band on every page */
    .band { position: fixed; top: -21mm; left: 0; right: 0; height: 16mm; background: #ab222f; color: #fff; padding: 0 4mm; border-radius: 8px; }
    .band table { width: 100%; border-collapse: collapse; height: 16mm; }
    .band td { color: #fff; vertical-align: middle; padding: 0; border: 0; }
    .band td.logo { width: 15mm; }
    .band img { height: 13mm; width: 13mm; }
    .band .brand { font-size: 17px; font-weight: bold; letter-spacing: 1px; padding-left: 3mm; }
    .band .title { font-size: 12px; font-weight: bold; text-align: right; }
    .band .meta { font-size: 8px; color: #f4e8cf; text-align: right; }

    /* Footer with page numbers */
    .foot { position: fixed; bottom: -10mm; left: 0; right: 0; height: 6mm; border-top: 0.5pt solid #c9b48a; color: #777; font-size: 8px; padding-top: 1.5mm; }
    .foot .l { float: left; }
    .foot .r { float: right; }
    .foot .r:after { content: "Page " counter(page); }

    h2 { font-size: 11.5px; margin: 12px 0 4px; padding: 5px 9px; color: #7a1520; background: #f4e8cf; border-left: 3pt solid #ab222f; border-radius: 6px; }
    h2.first { margin-top: 0; }
    .break { page-break-before: always; }

    table.kv { border-collapse: collapse; margin: 2px 0 4px; }
    table.kv td { padding: 3px 8px; border: 0.5pt solid #ddd; vertical-align: top; }
    table.kv td.k { color: #666; background: #fbf8f1; width: 190px; }

    table.data { width: 100%; border-collapse: collapse; margin-top: 4px; }
    table.data th { background: #f4e8cf; color: #5a2a2e; text-align: left; padding: 4px 6px; border: 0.5pt solid #c9b48a; font-weight: bold; }
    table.data td { padding: 3px 6px; border: 0.5pt solid #d6d0c4; vertical-align: top; white-space: nowrap; }
    table.data td:first-child, table.data td.wrap { white-space: normal; word-wrap: break-word; }
    table.data th { white-space: nowrap; }
    table.data tr:nth-child(even) td { background: #fbf8f1; }
    table.data td.num { text-align: right; white-space: nowrap; }
    table.data td.empty { color: #888; text-align: center; padding: 6px; }
    thead { display: table-header-group; }
</style>
</head>
<body>
    <div class="band">
        <table>
            <tr>
                @if ($logo)
                    <td class="logo"><img src="{{ $logo }}" alt=""></td>
                @endif
                <td class="brand">LORD JEWELLER</td>
                <td>
                    <div class="title">{{ $title }}</div>
                    <div class="meta">Generated {{ $generatedAt }}</div>
                </td>
            </tr>
        </table>
    </div>
    <div class="foot">
        <span class="l">Lord Jeweller · {{ $title }} · {{ $generatedAt }}</span>
        <span class="r"></span>
    </div>

    @foreach ($sections as $section)
        @php $break = ! $loop->first && ! empty($section['page_break']); @endphp
        @if (! empty($section['heading']))
            <h2 class="{{ $loop->first ? 'first' : '' }} {{ $break ? 'break' : '' }}">{{ $section['heading'] }}</h2>
        @elseif ($break)
            <div class="break"></div>
        @endif

        @if (! empty($section['kv']))
            <table class="kv">
                @foreach ($section['kv'] as $label => $value)
                    <tr><td class="k">{{ $label }}</td><td>{{ $value === null || $value === '' ? '—' : $value }}</td></tr>
                @endforeach
            </table>
        @endif

        @if (! empty($section['columns']))
            <table class="data">
                <thead><tr>@foreach ($section['columns'] as $col)<th>{{ $col }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($section['rows'] as $row)
                        <tr>@foreach ($row as $cell)<td class="{{ is_string($cell) && preg_match('/^[₹\-]?[\d,]+(\.\d+)?( g)?$/u', trim($cell)) ? 'num' : (is_string($cell) && mb_strlen($cell) > 24 ? 'wrap' : '') }}">{{ $cell === null || $cell === '' ? '—' : $cell }}</td>@endforeach</tr>
                    @empty
                        <tr><td class="empty" colspan="{{ count($section['columns']) }}">No records</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    @endforeach
</body>
</html>
