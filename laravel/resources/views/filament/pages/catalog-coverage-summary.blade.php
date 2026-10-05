@php
    /** @var array{totals: array, levels: list<array>} $summary */
    $totals = $summary['totals'];
    $percent = $totals['approved'] > 0 ? (int) floor($totals['in_products'] * 100 / $totals['approved']) : 0;
@endphp

<x-filament::section heading="Summary" collapsible>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr)); gap: 1rem; margin-bottom: 1rem;">
        <div>
            <div class="fi-section-header-description">Approved titles</div>
            <div style="font-size: 1.5rem; font-weight: 600;" data-coverage="approved">{{ number_format($totals['approved']) }}</div>
        </div>
        <div>
            <div class="fi-section-header-description">In my products</div>
            <div style="font-size: 1.5rem; font-weight: 600;" data-coverage="in_products">{{ number_format($totals['in_products']) }} ({{ $percent }}%)</div>
        </div>
        <div>
            <div class="fi-section-header-description">Not in my products</div>
            <div style="font-size: 1.5rem; font-weight: 600;" data-coverage="missing">{{ number_format($totals['missing']) }}</div>
        </div>
        <div>
            <div class="fi-section-header-description">My products not on the list</div>
            <div style="font-size: 1.5rem; font-weight: 600;" data-coverage="not_on_list">{{ number_format($totals['not_on_list']) }}</div>
        </div>
    </div>

    @if ($summary['levels'] !== [])
        <table class="fi-ta-table" style="width: 100%;">
            <thead>
                <tr>
                    <th class="fi-ta-header-cell" style="text-align: left;">Level</th>
                    <th class="fi-ta-header-cell" style="text-align: right;">Approved</th>
                    <th class="fi-ta-header-cell" style="text-align: right;">In my products</th>
                    <th class="fi-ta-header-cell" style="text-align: right;">Not in my products</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($summary['levels'] as $row)
                    <tr>
                        <td class="fi-ta-cell">{{ $row['group'] }}</td>
                        <td class="fi-ta-cell" style="text-align: right;">{{ $row['approved'] }}</td>
                        <td class="fi-ta-cell" style="text-align: right;">{{ $row['in_products'] }}</td>
                        <td class="fi-ta-cell" style="text-align: right;">{{ $row['missing'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="fi-section-header-description">No approved list has been published yet.</p>
    @endif
</x-filament::section>
