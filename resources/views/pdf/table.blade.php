<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 14mm 10mm; }
        * { font-family: DejaVu Sans, sans-serif; }
        body { margin: 0; color: #2b1a1a; font-size: 9px; }
        .head { border-bottom: 2px solid #ab222f; padding-bottom: 6px; margin-bottom: 10px; }
        .brand { color: #ab222f; font-size: 16px; font-weight: bold; letter-spacing: .5px; }
        .title { font-size: 11px; color: #5b3a2e; margin-top: 2px; }
        .meta { float: right; text-align: right; font-size: 8px; color: #8a6f63; }
        table { border-collapse: collapse; width: 100%; }
        th { background: #ab222f; color: #fff; text-align: left; padding: 5px 6px; font-size: 8.5px; }
        td { padding: 4px 6px; border-bottom: 1px solid #eadfdc; }
        tr:nth-child(even) td { background: #faf5f2; }
        .empty { padding: 20px; text-align: center; color: #8a6f63; }
    </style>
</head>
<body>
    <div class="head">
        <div class="meta">Generated: {{ $generatedAt }}<br>{{ count($rows) }} record(s)</div>
        <div class="brand">LORD JEWELLER</div>
        <div class="title">{{ $title }}</div>
    </div>

    @if (count($rows) === 0)
        <div class="empty">No records to export.</div>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($headers as $h)
                        <th>{{ $h }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        @foreach ($row as $cell)
                            <td>{{ $cell }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
