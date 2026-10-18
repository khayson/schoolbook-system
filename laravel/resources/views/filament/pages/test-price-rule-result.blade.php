@php
    /** @var array<string, mixed>|null $preview */
@endphp

@if ($preview)
    <div class="space-y-4 text-sm">
        <div class="grid grid-cols-2 gap-2 md:grid-cols-4">
            <div><span class="text-gray-500">Subtotal</span><div class="font-semibold">{{ number_format($preview['subtotal']) }}</div></div>
            <div><span class="text-gray-500">Order discount</span><div class="font-semibold">{{ number_format($preview['discount_total']) }}</div></div>
            <div><span class="text-gray-500">Tax</span><div class="font-semibold">{{ number_format($preview['tax_total']) }}</div></div>
            <div><span class="text-gray-500">Total</span><div class="font-semibold">{{ number_format($preview['total']) }}</div></div>
        </div>

        @if (! empty($preview['warnings']))
            <div class="rounded-lg bg-warning-50 p-3 text-warning-800 dark:bg-warning-500/10 dark:text-warning-400">
                <div class="font-medium">Warnings</div>
                <ul class="list-disc pl-5">
                    @foreach ($preview['warnings'] as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2 pr-2">Product</th>
                        <th class="py-2 pr-2">Qty</th>
                        <th class="py-2 pr-2">Base</th>
                        <th class="py-2 pr-2">Unit</th>
                        <th class="py-2 pr-2">Line</th>
                        <th class="py-2">Rules</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($preview['lines'] as $line)
                        <tr class="border-b border-gray-100 dark:border-gray-800 align-top">
                            <td class="py-2 pr-2">{{ $line['product_title'] }}</td>
                            <td class="py-2 pr-2">{{ $line['quantity'] }}</td>
                            <td class="py-2 pr-2">{{ number_format($line['base_price']) }}</td>
                            <td class="py-2 pr-2">{{ number_format($line['unit_price']) }}</td>
                            <td class="py-2 pr-2">{{ number_format($line['line_total']) }}</td>
                            <td class="py-2">
                                @forelse ($line['applied_rules'] as $rule)
                                    <div>{{ $rule['name'] }} <span class="text-gray-500">({{ $rule['effect']['type'] ?? '' }})</span></div>
                                @empty
                                    <span class="text-gray-400">—</span>
                                @endforelse
                                @foreach ($line['warnings'] ?? [] as $w)
                                    <div class="text-warning-600">{{ $w }}</div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
