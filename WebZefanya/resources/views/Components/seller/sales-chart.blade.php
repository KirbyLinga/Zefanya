@props(['summary'])

@php
    $days = $summary['days'];
    $axisMax = max(1, $summary['axisMaxMinor']);
    $peso = fn (int $minor): string => '₱'.number_format($minor / 100, 2);
    $orderWord = fn (int $count): string => number_format($count).' '.\Illuminate\Support\Str::plural('order', $count);
    $change = $summary['changePercent'];
    $lastIndex = $days->count() - 1;
@endphp

<div data-sales-chart>
    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2 mb-6">
        <div>
            <p class="font-sans text-[28px] font-extrabold leading-tight text-[var(--text-ink)]">{{ $peso($summary['totalMinor']) }}</p>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] mt-1">from {{ $orderWord($summary['orderCount']) }} delivered in the last 7 days</p>
        </div>
        <div class="font-sans text-[12.5px] text-right max-sm:text-left">
            @if ($change !== null && $change > 0)
                <p class="inline-flex items-center gap-1 font-semibold text-[var(--status-success)]"><i data-lucide="trending-up" class="w-[15px] h-[15px]" aria-hidden="true"></i>Up {{ $change }}% from the 7 days before</p>
            @elseif ($change !== null && $change < 0)
                <p class="inline-flex items-center gap-1 font-semibold text-[var(--status-danger)]"><i data-lucide="trending-down" class="w-[15px] h-[15px]" aria-hidden="true"></i>Down {{ abs($change) }}% from the 7 days before</p>
            @elseif ($change === 0)
                <p class="font-semibold text-[var(--text-muted)]">Same as the 7 days before</p>
            @elseif ($summary['totalMinor'] > 0)
                <p class="font-semibold text-[var(--text-muted)]">No sales in the 7 days before</p>
            @endif
            @if ($summary['bestDay'])
                <p class="text-[var(--text-muted)] mt-1">Best day: {{ $summary['bestDay']['label'] }}, {{ $summary['bestDay']['date'] }}</p>
            @endif
        </div>
    </div>

    @if ($summary['totalMinor'] === 0)
        <div class="flex flex-col items-center justify-center text-center py-10 px-4">
            <i data-lucide="bar-chart-3" class="w-[28px] h-[28px] text-[var(--icon-soft)] mb-3" aria-hidden="true"></i>
            <p class="font-sans text-sm font-semibold text-[var(--text-ink)] mb-1">No sales in the last 7 days</p>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] max-w-[320px]">Your daily sales will appear here as soon as an order is delivered.</p>
        </div>
    @else
        <div class="relative pl-[52px] pt-6">
            {{-- Gridlines and peso axis --}}
            <div class="absolute left-0 right-0 top-6 h-[200px]" aria-hidden="true">
                @foreach ($summary['ticks'] as $tick)
                    <div class="absolute left-0 right-0 flex items-center gap-2" style="bottom: {{ $tick / $axisMax * 100 }}%; transform: translateY(50%);">
                        <span class="w-[44px] shrink-0 text-right font-sans text-[11.5px] tabular-nums text-[var(--text-muted)]">{{ \App\Services\Seller\SellerSalesTrend::compactPeso($tick) }}</span>
                        <span class="h-px flex-1 bg-[var(--border-strong)]"></span>
                    </div>
                @endforeach
            </div>

            <ul class="relative flex" aria-label="Sales for each of the last 7 days">
                @foreach ($days as $index => $day)
                    @php
                        $height = $day['value'] / $axisMax * 100;
                        $isBest = $summary['bestDay'] && $day['date'] === $summary['bestDay']['date'];
                        $tipPosition = match (true) {
                            $index === 0 => 'left-0',
                            $index === $lastIndex => 'right-0',
                            default => 'left-1/2 -translate-x-1/2',
                        };
                    @endphp
                    <li
                        tabindex="0"
                        class="group relative flex-1 min-w-0 rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-[var(--brand)]"
                        aria-label="{{ $day['label'] }}, {{ $day['date'] }}: {{ $peso($day['value']) }} from {{ $orderWord($day['orders']) }}"
                    >
                        <div class="relative flex h-[200px] items-end justify-center group-hover:bg-[var(--bg-chip)]/50 group-focus-visible:bg-[var(--bg-chip)]/50">
                            @if ($day['value'] > 0)
                                <div class="w-full max-w-[24px] rounded-t-[4px] bg-[var(--brand)]" style="height: {{ $height }}%; min-height: 3px;"></div>
                                @if ($isBest)
                                    <span class="absolute left-1/2 -translate-x-1/2 whitespace-nowrap font-sans text-[11.5px] font-semibold text-[var(--text-ink)] group-hover:hidden group-focus-visible:hidden" style="bottom: calc({{ $height }}% + 4px);" aria-hidden="true">{{ \App\Services\Seller\SellerSalesTrend::compactPeso($day['value']) }}</span>
                                @endif
                            @endif

                            <div class="pointer-events-none absolute z-10 hidden w-max rounded-md bg-[var(--tooltip-bg)] px-3 py-2 font-sans text-[var(--tooltip-text)] shadow-sm group-hover:block group-focus-visible:block {{ $tipPosition }}" style="bottom: calc({{ $height }}% + 6px);" aria-hidden="true">
                                <p class="text-[13.5px] font-semibold text-[var(--tooltip-text)]">{{ $peso($day['value']) }}</p>
                                <p class="text-[11.5px] text-[var(--tooltip-text)] opacity-80">{{ $day['label'] }}, {{ $day['date'] }} · {{ $orderWord($day['orders']) }}</p>
                            </div>
                        </div>
                        <p class="mt-2 text-center font-sans text-[11.5px] {{ $day['isToday'] ? 'font-semibold text-[var(--text-ink)]' : 'text-[var(--text-muted)]' }}" aria-hidden="true">{{ $day['label'] }}</p>
                        <p class="text-center font-sans text-[11px] text-[var(--text-muted)] max-sm:hidden" aria-hidden="true">{{ $day['date'] }}</p>
                    </li>
                @endforeach
            </ul>
        </div>

        <details class="mt-4 font-sans text-[12.5px]">
            <summary class="cursor-pointer font-semibold text-[var(--link)]">View as table</summary>
            <table class="mt-3 w-full border-collapse">
                <thead>
                    <tr>
                        <th class="py-2 text-left text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] border-b border-[var(--border-strong)]">Day</th>
                        <th class="py-2 text-right text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] border-b border-[var(--border-strong)]">Orders</th>
                        <th class="py-2 text-right text-[11px] font-semibold uppercase tracking-[0.6px] text-[var(--text-muted)] border-b border-[var(--border-strong)]">Sales</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($days as $day)
                        <tr>
                            <td class="py-2 text-[var(--text-ink)] border-b border-[var(--border-strong)]">{{ $day['label'] }}, {{ $day['date'] }}</td>
                            <td class="py-2 text-right tabular-nums text-[var(--text-ink)] border-b border-[var(--border-strong)]">{{ number_format($day['orders']) }}</td>
                            <td class="py-2 text-right tabular-nums text-[var(--text-ink)] border-b border-[var(--border-strong)]">{{ $peso($day['value']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </details>
    @endif
</div>
