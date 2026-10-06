@php
    use App\Services\Money;
    /** @var array{columns: array<string, array{0: string, 1: string}>, rows: list<array>, totals?: array|null, summary?: array<string, string>, note?: string|null} $report */
    $cell = function (mixed $value, string $type): string {
        if ($value === null) {
            return '—';
        }

        return match ($type) {
            'money' => Money::formatGhsGrouped((int) $value),
            'int' => number_format((int) $value),
            default => (string) $value,
        };
    };
    $align = fn (string $type): string => in_array($type, ['money', 'int'], true) ? 'text-align: right; white-space: nowrap;' : 'text-align: left;';
@endphp

<div>
    @if (! empty($report['note']))
        <x-filament::section compact>
            <div class="fi-section-header-description" data-report-note>{{ $report['note'] }}</div>
        </x-filament::section>
    @endif

    @if (! empty($report['summary']))
        <x-filament::section compact style="margin-top: 1rem;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 1rem;">
                @foreach ($report['summary'] as $label => $value)
                    <div>
                        <div class="fi-section-header-description">{{ $label }}</div>
                        <div style="font-size: 1.25rem; font-weight: 600;" data-summary="{{ $label }}">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <x-filament::section style="margin-top: 1rem;">
        @if ($report['rows'] === [])
            <div class="fi-section-header-description">Nothing to show for these filters.</div>
        @else
            <div style="overflow-x: auto;">
                <table class="fi-ta-table" style="width: 100%;" data-report-table>
                    <thead>
                        <tr>
                            @foreach ($report['columns'] as $key => [$label, $type])
                                <th class="fi-ta-header-cell" style="{{ $align($type) }} padding: 0.5rem;">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['rows'] as $row)
                            <tr class="fi-ta-row">
                                @foreach ($report['columns'] as $key => [$label, $type])
                                    <td class="fi-ta-cell" style="{{ $align($type) }} padding: 0.5rem;">{{ $cell($row[$key] ?? null, $type) }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    @if (! empty($report['totals']))
                        <tfoot>
                            <tr style="font-weight: 600; border-top: 2px solid rgba(127,127,127,.4);">
                                @foreach ($report['columns'] as $key => [$label, $type])
                                    <td style="{{ $align($type) }} padding: 0.5rem;" data-total="{{ $key }}">{{ $loop->first && ! array_key_exists($key, $report['totals']) ? 'Total' : (array_key_exists($key, $report['totals']) ? $cell($report['totals'][$key], $type) : '') }}</td>
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        @endif
    </x-filament::section>
</div>
