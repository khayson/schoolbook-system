<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Count sheet {{ $count->reference }}</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #222; }
        h1 { font-size: 16px; margin: 0 0 4px; letter-spacing: 1px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; }
        .lines { margin-top: 12px; }
        .lines thead { display: table-header-group; }
        .lines tr { page-break-inside: avoid; }
        .lines th { background: #f0f0f0; text-align: left; padding: 5px; border-bottom: 1px solid #bbb; font-size: 9px; text-transform: uppercase; }
        .lines td { padding: 7px 5px; border-bottom: 1px solid #ddd; }
        .count { width: 18%; border-left: 1px solid #bbb; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td>
                <h1>{{ $business['name'] !== '' ? $business['name'] : config('app.name') }}</h1>
                <div class="muted">Stock count sheet</div>
            </td>
            <td style="text-align: right;">
                <h1>{{ $count->reference }}</h1>
                <div class="muted">Opened {{ $count->created_at?->format('d M Y H:i') }} · {{ $items->count() }} products</div>
            </td>
        </tr>
    </table>
    <div style="margin-top: 8px;"><strong>Before counting:</strong> record every sale already made, including handwritten ones. A sale keyed in after its shelf was counted makes that count wrong.</div>
    @if ($count->notes)
        <div style="margin-top: 8px;"><span class="muted">Notes:</span> {{ $count->notes }}</div>
    @endif

    <table class="lines">
        <thead>
            <tr>
                <th style="width: 14%;">SKU</th>
                <th>Title</th>
                <th style="width: 14%;">Level</th>
                <th style="width: 16%;">Subject</th>
                <th class="count">Counted</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td>{{ $item->product?->sku }}</td>
                    <td>{{ $item->product?->title }}</td>
                    <td>{{ $item->product?->level?->name }}</td>
                    <td>{{ $item->product?->subject?->name }}</td>
                    <td class="count"></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div style="margin-top: 16px;" class="muted">Counted by: ______________________ &nbsp; Date: ______________</div>
</body>
</html>
