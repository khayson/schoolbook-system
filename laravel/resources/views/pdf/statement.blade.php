@php
    use App\Services\Money;
    use Illuminate\Support\Carbon;
    $customer = $statement['customer'];
    $balanceLabel = fn (int $b) => $b < 0 ? Money::formatGhsGrouped(-$b).' CR' : Money::formatGhsGrouped($b);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement {{ $customer['code'] }}</title>
    <style>
        @page { margin: 24px 28px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #222; }
        h1 { font-size: 17px; margin: 0 0 4px; letter-spacing: 1px; }
        .muted { color: #666; }
        table { width: 100%; border-collapse: collapse; }
        .header td { vertical-align: top; }
        .meta td { padding: 2px 0; }
        .lines { margin-top: 14px; }
        .lines th { background: #f0f0f0; text-align: left; padding: 5px; border-bottom: 1px solid #bbb; font-size: 9px; text-transform: uppercase; }
        .lines td { padding: 5px; border-bottom: 1px solid #e5e5e5; }
        .lines tr.total td { font-weight: bold; border-top: 1px solid #222; border-bottom: none; }
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
                <h1>STATEMENT</h1>
                <table class="meta">
                    <tr><td class="muted">Period</td><td class="num">{{ Carbon::parse($statement['from'])->format('d M Y') }} to {{ Carbon::parse($statement['to'])->format('d M Y') }}</td></tr>
                    <tr><td class="muted">Printed</td><td class="num">{{ now()->format('d M Y H:i') }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div style="margin-top: 14px;">
        <div class="muted">Customer</div>
        <strong>{{ $customer['name'] }}</strong> ({{ $customer['code'] }})
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>Date</th>
                <th>Details</th>
                <th class="num">Charges</th>
                <th class="num">Payments / credits</th>
                <th class="num">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ Carbon::parse($statement['from'])->format('d M Y') }}</td>
                <td>Opening balance</td>
                <td></td>
                <td></td>
                <td class="num">{{ $balanceLabel($statement['opening_balance']) }}</td>
            </tr>
            @foreach ($statement['lines'] as $line)
                <tr>
                    <td>{{ Carbon::parse($line['at'])->format('d M Y H:i') }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td class="num">{{ $line['debit'] > 0 ? Money::formatGhsGrouped($line['debit']) : '' }}</td>
                    <td class="num">{{ $line['credit'] > 0 ? Money::formatGhsGrouped($line['credit']) : '' }}</td>
                    <td class="num">{{ $balanceLabel($line['balance']) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td></td>
                <td>Closing balance</td>
                <td class="num">{{ Money::formatGhsGrouped($statement['totals']['debits']) }}</td>
                <td class="num">{{ Money::formatGhsGrouped($statement['totals']['credits']) }}</td>
                <td class="num">{{ $balanceLabel($statement['closing_balance']) }}</td>
            </tr>
        </tbody>
    </table>

    <div style="margin-top: 10px;" class="muted">
        A balance marked CR is money held for the customer (credit).
    </div>

    @if ($business['footer'] !== '')
        <div class="footer">{!! nl2br(e($business['footer'])) !!}</div>
    @endif
</body>
</html>
