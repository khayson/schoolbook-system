@php
    use App\Services\Money;
    $customer = $payment->customer;
    $methods = ['cash' => 'Cash', 'momo' => 'Mobile Money', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $payment->receipt_no }}</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #222; }
        h1 { font-size: 17px; margin: 0 0 4px; letter-spacing: 1px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; }
        .meta td { padding: 2px 0; }
        .amount { margin-top: 14px; padding: 10px; border: 1px solid #222; text-align: center; font-size: 15px; font-weight: bold; }
        .lines { margin-top: 14px; }
        .lines th { background: #f0f0f0; text-align: left; padding: 5px; border-bottom: 1px solid #bbb; font-size: 9px; text-transform: uppercase; }
        .lines td { padding: 5px; border-bottom: 1px solid #e5e5e5; }
        .num { text-align: right; white-space: nowrap; }
        .footer { margin-top: 22px; padding-top: 8px; border-top: 1px solid #ddd; font-size: 9px; color: #555; }
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
                <h1>RECEIPT</h1>
                <table class="meta">
                    <tr><td class="muted">Receipt no.</td><td class="num"><strong>{{ $payment->receipt_no }}</strong></td></tr>
                    <tr><td class="muted">Date paid</td><td class="num">{{ $payment->paid_at?->format('d M Y') }}</td></tr>
                    <tr><td class="muted">Method</td><td class="num">{{ $methods[$payment->method->value] ?? $payment->method->value }}</td></tr>
                    @if ($payment->reference)
                        <tr><td class="muted">Reference</td><td class="num">{{ $payment->reference }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <div style="margin-top: 14px;">
        <div class="muted">Received from</div>
        <strong>{{ $customer->name }}</strong> ({{ $customer->code }})
    </div>

    <div class="amount">{{ Money::formatGhsGrouped($payment->amount) }}</div>

    @if ($lines->isNotEmpty())
        <table class="lines">
            <thead>
                <tr>
                    <th>Applied to invoice</th>
                    <th class="num">Amount applied</th>
                    <th class="num">Balance remaining</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($lines as $line)
                    <tr>
                        <td>{{ $line['invoice_no'] }}</td>
                        <td class="num">{{ Money::formatGhsGrouped($line['amount']) }}</td>
                        <td class="num">{{ Money::formatGhsGrouped($line['balance_due']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="lines">
        <tr><td>Unapplied from this payment (held as credit)</td><td class="num">{{ Money::formatGhsGrouped($payment->unallocated_amount) }}</td></tr>
        <tr><td>Customer's total credit</td><td class="num">{{ Money::formatGhsGrouped($customer->credit_balance) }}</td></tr>
        <tr><td>Customer's outstanding balance</td><td class="num">{{ Money::formatGhsGrouped($customer->outstanding_balance) }}</td></tr>
    </table>

    @if ($payment->notes)
        <div style="margin-top: 10px;"><span class="muted">Notes:</span> {{ $payment->notes }}</div>
    @endif

    <div style="margin-top: 10px;" class="muted">Received by {{ $payment->receivedBy?->name }}</div>

    @if ($business['footer'] !== '')
        <div class="footer">{!! nl2br(e($business['footer'])) !!}</div>
    @endif
</body>
</html>
