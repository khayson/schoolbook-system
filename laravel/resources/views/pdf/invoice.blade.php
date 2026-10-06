@php
    use App\Services\Money;
    $customer = $sale->customer;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $sale->invoice_no }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
        h1 { font-size: 20px; margin: 0 0 4px; letter-spacing: 1px; }
        .muted { color: #666; }
        .header td { vertical-align: top; }
        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 2px 0; }
        .items { margin-top: 18px; }
        .items th { background: #f0f0f0; text-align: left; padding: 6px; border-bottom: 1px solid #bbb; font-size: 10px; text-transform: uppercase; }
        .items td { padding: 6px; border-bottom: 1px solid #e5e5e5; }
        .num { text-align: right; white-space: nowrap; }
        .totals { margin-top: 12px; width: 45%; margin-left: 55%; }
        .totals td { padding: 4px 6px; }
        .totals .grand td { border-top: 1px solid #222; font-weight: bold; font-size: 12px; }
        .totals .due td { font-weight: bold; }
        .footer { margin-top: 28px; padding-top: 8px; border-top: 1px solid #ddd; font-size: 10px; color: #555; }
    </style>
</head>
<body>
    <table class="header">
        <tr>
            <td style="width: 55%;">
                <h1>{{ $business['name'] !== '' ? $business['name'] : config('app.name') }}</h1>
                @if ($business['address'] !== '')
                    <div>{!! nl2br(e($business['address'])) !!}</div>
                @endif
                @if ($business['phone'] !== '')
                    <div>Tel: {{ $business['phone'] }}</div>
                @endif
            </td>
            <td style="width: 45%;" class="num">
                <h1>INVOICE</h1>
                <table class="meta">
                    <tr><td class="muted">Invoice no.</td><td class="num"><strong>{{ $sale->invoice_no }}</strong></td></tr>
                    <tr><td class="muted">Invoice date</td><td class="num">{{ $sale->confirmed_at?->format('d M Y') }}</td></tr>
                    <tr><td class="muted">Sale date</td><td class="num">{{ $sale->sale_date?->format('d M Y') }}</td></tr>
                    <tr><td class="muted">Due date</td><td class="num">{{ $sale->due_date?->format('d M Y') }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table style="margin-top: 18px;">
        <tr>
            <td>
                <div class="muted">Bill to</div>
                <strong>{{ $customer->name }}</strong> ({{ $customer->code }})<br>
                @if ($customer->contact_person)
                    Attn: {{ $customer->contact_person }}<br>
                @endif
                @if ($customer->address)
                    {!! nl2br(e($customer->address)) !!}<br>
                @endif
                {{ collect([$customer->district, $customer->region?->value])->filter()->implode(', ') }}
                @if ($customer->phone)
                    <br>Tel: {{ $customer->phone }}
                @endif
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width: 5%;">#</th>
                <th>Item</th>
                <th class="num" style="width: 10%;">Qty</th>
                <th class="num" style="width: 18%;">Unit price</th>
                <th class="num" style="width: 18%;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @if ($sale->is_opening_balance)
                <tr>
                    <td>1</td>
                    <td>Balance brought forward ({{ $sale->sale_date?->format('d M Y') }})</td>
                    <td class="num"></td>
                    <td class="num"></td>
                    <td class="num">{{ Money::formatGhsGrouped($sale->total) }}</td>
                </tr>
            @endif
            @foreach ($sale->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->product_title }}</td>
                    <td class="num">{{ number_format($item->quantity) }}</td>
                    <td class="num">{{ Money::formatGhsGrouped($item->unit_price) }}</td>
                    <td class="num">{{ Money::formatGhsGrouped($item->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="num">{{ Money::formatGhsGrouped($sale->subtotal) }}</td></tr>
        @if ($sale->discount_total > 0)
            <tr><td>Discount</td><td class="num">{{ Money::formatGhsGrouped(-$sale->discount_total) }}</td></tr>
        @endif
        @if ($sale->tax_total > 0)
            <tr><td>Tax</td><td class="num">{{ Money::formatGhsGrouped($sale->tax_total) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="num">{{ Money::formatGhsGrouped($sale->total) }}</td></tr>
        <tr><td>Amount paid</td><td class="num">{{ Money::formatGhsGrouped($sale->amount_paid) }}</td></tr>
        <tr class="due"><td>Balance due</td><td class="num">{{ Money::formatGhsGrouped($sale->balance_due) }}</td></tr>
    </table>

    @if ($business['footer'] !== '')
        <div class="footer">{!! nl2br(e($business['footer'])) !!}</div>
    @endif
</body>
</html>
