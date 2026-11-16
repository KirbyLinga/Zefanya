@props(['transfers', 'total' => null])

@php($total ??= $transfers->count())
@if ($total > 0)
    <section class="mb-6 rounded-xl border border-amber-200 bg-amber-50/70 p-4 shadow-sm" aria-labelledby="overdue-transfers-title" data-overdue-transfers>
        <h2 id="overdue-transfers-title" class="font-serif text-lg text-amber-950">{{ $total }} overdue {{ \Illuminate\Support\Str::plural('transfer', $total) }}</h2>
        <p class="mt-1 font-sans text-xs text-amber-900">Past the time allowed for their transport mode (road {{ \App\Services\Logistics\StuckTransferService::thresholdHours() }} h, sea {{ \App\Services\Logistics\StuckTransferService::thresholdHours('SEA') }} h, air {{ \App\Services\Logistics\StuckTransferService::thresholdHours('AIR') }} h) and not received. Overdue does not mean lost: the sending hub keeps custody until the receiving hub scans each parcel, and nothing moves on its own.</p>
        <ul class="mt-3 divide-y divide-amber-200">
            @foreach ($transfers as $transfer)
                <li class="flex flex-wrap items-center justify-between gap-2 py-2 font-sans text-[12.5px] text-amber-950" data-overdue-{{ $transfer['kind'] }}="{{ $transfer['id'] }}">
                    <span><span class="font-semibold">{{ $transfer['reference'] }}</span> · {{ $transfer['route'] }} · {{ $transfer['pending'] }} awaiting receipt · {{ strtolower($transfer['mode']) }} · dispatched {{ $transfer['dispatchedAt']?->diffForHumans() }}</span>
                    @if ($transfer['url'])
                        <a href="{{ $transfer['url'] }}" class="font-semibold text-amber-900 underline">Open {{ $transfer['kind'] === 'batch' ? 'batch' : 'handoff' }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>
@endif
