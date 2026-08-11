<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #222; }
    h1 { font-size: 15px; color: #ab222f; margin: 0 0 2px; }
    .meta { color: #777; font-size: 9px; margin-bottom: 12px; }
    h2 { font-size: 12px; margin: 14px 0 4px; color: #ab222f; }
    table.kv td { padding: 2px 10px 2px 0; }
    table.kv td.k { color: #666; }
    table.data { width: 100%; border-collapse: collapse; margin-top: 3px; }
    table.data th { background: #f4e8cf; text-align: left; padding: 4px 6px; border: 0.5pt solid #bbb; }
    table.data td { padding: 3px 6px; border: 0.5pt solid #ccc; }
</style>
</head>
<body>
    <h1>LORD JEWELLER — {{ $title }}</h1>
    <div class="meta">Generated {{ $generatedAt }}</div>

    @foreach ($sections as $section)
        @if (! empty($section['heading']))
            <h2>{{ $section['heading'] }}</h2>
        @endif

        @if (! empty($section['kv']))
            <table class="kv">
                @foreach ($section['kv'] as $label => $value)
                    <tr><td class="k">{{ $label }}</td><td>{{ $value }}</td></tr>
                @endforeach
            </table>
        @endif

        @if (! empty($section['columns']))
            <table class="data">
                <thead><tr>@foreach ($section['columns'] as $col)<th>{{ $col }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($section['rows'] as $row)
                        <tr>@foreach ($row as $cell)<td>{{ $cell }}</td>@endforeach</tr>
                    @empty
                        <tr><td colspan="{{ count($section['columns']) }}">No records</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endif
    @endforeach
</body>
</html>
