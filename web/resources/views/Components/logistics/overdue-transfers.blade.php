@props(['transfers', 'total'])

@if ($total > 0)
    <section class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-amber-950" aria-label="Overdue transfers">
        <h2 class="text-sm font-semibold">Overdue transfers ({{ $total }})</h2>
        <p class="mt-1 text-xs">These transfers are awaiting verified receipt.</p>
        <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm">
            @foreach ($transfers as $transfer)
                @php($batch = $transfer instanceof \App\Models\Logistics\Manifest)
                <li><a class="underline" href="{{ route($batch ? 'logistics.manifests.show' : 'logistics.handoffs.show', $transfer) }}">{{ $batch ? 'Batch' : 'Handoff' }} #{{ $transfer->id }}</a></li>
            @endforeach
        </ul>
    </section>
@endif
