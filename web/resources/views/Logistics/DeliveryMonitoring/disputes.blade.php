@extends($admin ? 'Admin.Layouts.app' : 'Layouts.logistics')

@section('title', 'Parcel non-receipt disputes')

@section('content')
<h1 class="mb-4 font-serif text-2xl">Parcel non-receipt disputes</h1>
@if (session('success'))<p role="status" class="mb-4 text-sm">{{ session('success') }}</p>@endif
@foreach ($errors->all() as $error)<p role="alert" class="mb-2 text-sm text-red-800">{{ $error }}</p>@endforeach
@if ($admin)
    <details class="mb-4 rounded-lg border p-4">
        <summary class="cursor-pointer text-sm font-semibold">Record an authorized late report</summary>
        <form class="mt-2 grid gap-2 text-sm" method="POST" action="{{ route('admin.delivery-disputes.exception') }}">
            @csrf
            <label>Shipment ID <input name="shipment_id" type="number" min="1" required class="block rounded border p-2"></label>
            <label>Buyer’s explanation <textarea name="explanation" required maxlength="1000" class="block w-full rounded border p-2"></textarea></label>
            <label>Why this report was accepted <textarea name="reason" required maxlength="1000" class="block w-full rounded border p-2"></textarea></label>
            <button class="min-h-10 rounded bg-primary-800 px-4 text-white">Record exception</button>
        </form>
    </details>
@endif
@forelse ($cases as $case)
    @php
        $shipment = $case->sellerOrder;
        $proof = $shipment->deliveryProof;
        $prefix = $admin ? 'admin' : 'logistics';
    @endphp
    <article class="mb-4 space-y-2 rounded-lg border p-4 text-sm">
        <h2 class="font-semibold">Case #{{ $case->id }} · {{ $shipment->tracking_number }} · {{ ucfirst($case->status) }}</h2>
        <p>{{ $case->explanation }}</p>
        @if ($case->evidence_path)
            <a class="underline" href="{{ route($prefix.'.delivery-disputes.evidence', $case) }}">View Buyer’s supporting photo</a>
        @endif
        <p>Delivered {{ $case->delivered_at->timezone('Asia/Manila')->format('M j, Y g:i A') }} · Investigator {{ $case->investigator ?? 'Unassigned' }}</p>
        <p>Delivery Rider #{{ $proof?->rider_id ?? $shipment->delivery_courier_id ?? '—' }} · Recorded recipient {{ $proof?->received_by ?? ($proof?->recipient_acknowledgement['category'] ?? '—') }}</p>
        @if ($proof)
            <p>Physical handover {{ ($proof->recipient_acknowledgement['physical_handover'] ?? false) ? 'recorded' : 'unavailable' }} · Recipient acceptance {{ ($proof->recipient_acknowledgement['recipient_accepted'] ?? false) ? 'recorded' : 'unavailable' }}</p>
            <a class="underline" href="{{ route($prefix.'.delivery-disputes.proof', $case) }}">View private delivery proof</a>
        @endif
        @if ($shipment->payment?->collected_at)
            <p>COD recorded as collected ₱{{ number_format($shipment->payment->collected_amount_minor / 100, 2) }}</p>
        @endif
        @if ($case->already_paid_minor > 0)
            <p>Seller payout already recorded when filed: ₱{{ number_format($case->already_paid_minor / 100, 2) }}. Review separately before recording a financial adjustment.</p>
        @endif
        <details>
            <summary class="cursor-pointer font-semibold">Shipment history and incidents</summary>
            <ul class="ml-4 list-disc">
                @foreach ($shipment->statusHistories->sortBy('id') as $entry)
                    <li>{{ $entry->to_status->label() }} · {{ $entry->created_at?->timezone('Asia/Manila')->format('M j, Y g:i A') }} · {{ $entry->note }}</li>
                @endforeach
                @foreach ($shipment->incidentSet as $incident)
                    <li>Incident #{{ $incident->id }} · {{ $incident->typeLabel() }}</li>
                @endforeach
            </ul>
        </details>
        @if ($case->decision)<p>{{ ucfirst($case->outcome ?? 'Review') }} · {{ $case->decision }}</p>@endif
        @if (($admin && $case->status === 'escalated') || (! $admin && in_array($case->status, ['open', 'investigating'], true)))
            <form method="POST" action="{{ route($prefix.'.delivery-disputes.review', $case) }}" class="grid gap-2">
                @csrf
                @unless ($admin)
                    <label>Responsible investigator <input class="block rounded border p-2" name="investigator" value="{{ $case->investigator }}" maxlength="120" required></label>
                @endunless
                <label>Action
                    <select class="block rounded border p-2" name="action">
                        @unless ($admin)
                            <option value="investigate">Record investigation</option>
                            <option value="escalate">Escalate to Admin</option>
                        @endunless
                        <option value="upheld">Uphold claim for finance follow-up</option>
                        <option value="denied">Deny claim and release hold</option>
                    </select>
                </label>
                <label>Investigation note <textarea name="note" class="block w-full rounded border p-2" required maxlength="1000"></textarea></label>
                <button class="min-h-10 rounded bg-primary-800 px-4 text-white">Record action</button>
            </form>
        @elseif ($admin && $case->outcome === 'upheld' && ! $case->financial_reviewed_at)
            <form method="POST" action="{{ route($prefix.'.delivery-disputes.review', $case) }}" class="grid gap-2">
                @csrf
                <input type="hidden" name="action" value="release_hold">
                <label>Finance decision and adjustment reference, if applicable <textarea class="block w-full rounded border p-2" name="note" required maxlength="1000"></textarea></label>
                <button class="min-h-10 rounded bg-primary-800 px-4 text-white">Record finance review and release hold</button>
            </form>
        @endif
    </article>
@empty
    <p>No disputes to review.</p>
@endforelse
{{ $cases->links() }}
@endsection
