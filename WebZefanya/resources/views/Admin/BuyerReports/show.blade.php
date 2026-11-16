@extends('Admin.Layouts.app')
@section('title', 'Review Buyer report')
@section('content')
    <a class="text-sm font-semibold text-primary-800 underline" href="{{ route('admin.buyer-reports.index') }}">Back to Buyer reports</a>
    <h1 class="mt-4 text-2xl font-semibold text-[var(--text-ink)]">Buyer report #{{ $report->id }}</h1>
    <x-logistics-flash :messages="['success', 'warning', 'error']" />
    <x-form-errors :show="$errors->any()" :messages="$report->status === 'open' ? collect($errors->getMessages())->except(['decision', 'decision_note'])->flatten()->all() : $errors->all()" />
    <section class="mt-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 text-sm text-[var(--text-ink)]">
        <h2 class="font-semibold">Reported concern</h2>
        <p class="mt-2">Order {{ $report->sellerOrder?->order?->reference }} · shipment #{{ $report->seller_order_id }} · Buyer #{{ $report->buyer_id }} · Seller: {{ $report->seller?->business_name ?? 'Seller' }}</p>
        <p class="mt-2 font-semibold">{{ \App\Models\Shared\BuyerConductReport::REASONS[$report->reason] ?? 'Concern' }}</p>
        <p class="mt-2 whitespace-pre-wrap break-words">{{ $report->description }}</p>
        @foreach ($report->evidence ?? [] as $index => $path)
            <a class="mt-3 inline-block text-primary-800 underline" href="{{ route('admin.buyer-reports.evidence', [$report, $index]) }}" target="_blank" rel="noopener">View private evidence {{ $index + 1 }}</a>
        @endforeach
    </section>
    <section class="mt-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 text-sm text-[var(--text-ink)]">
        <h2 class="font-semibold">Recorded Buyer-initiated cancellations · {{ $orderCount }} distinct orders</h2>
        <p class="mt-2 text-xs text-[var(--text-muted)]">Initiating cancellation does not establish fault. Consider delivery delays, Seller issues and circumstances outside the Buyer's control. Seller and system cancellations are excluded; legacy records without a verified actor are excluded. There is no automatic misconduct threshold.</p>
        <ul class="mt-3 space-y-2">
            @forelse ($cancellations as $cancellation)
                <li class="rounded-md border border-[var(--border-strong)] p-3">{{ $cancellation->sellerOrder?->order?->reference }} · shipment #{{ $cancellation->seller_order_id }} · {{ manila($cancellation->created_at, 'M j, Y g:i A') }}<p class="mt-1 whitespace-pre-wrap break-words">{{ $cancellation->note }}</p></li>
            @empty
                <li>No cancellations attributed to this Buyer in the recorded history.</li>
            @endforelse
        </ul>
        <div class="mt-4">{{ $cancellations->links() }}</div>
    </section>
    <section class="mt-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 text-sm text-[var(--text-ink)]">
        <h2 class="font-semibold">Review decision</h2>
        @if ($conductReview)
            <p class="mt-2">This report is decided through conduct review <a class="font-semibold text-primary-800 underline" href="{{ route('admin.conduct-reviews.show', $conductReview->id) }}">{{ $conductReview->reference() }}</a> ({{ $conductReview->statusLabel() }}@if ($conductReview->decision) · {{ $conductReview->decisionLabel() }}@endif).</p>
            @if ($report->status !== 'open')
                <p class="mt-2 text-xs text-[var(--text-muted)]">{{ \App\Models\Shared\BuyerConductReport::DECISIONS[$report->status] ?? 'Reviewed' }} · {{ manila($report->reviewed_at, 'M j, Y g:i A') }}</p>
            @endif
        @elseif ($report->status === 'open')
            <p class="mt-2 text-xs text-[var(--text-muted)]">Record your review here. Account status, cancellation eligibility, COD restrictions and financial records are not changed by this decision.</p>
            <form method="POST" action="{{ route('admin.buyer-reports.decide', $report) }}" class="mt-4 space-y-3" data-submit-guard>
                @csrf
                <label class="block" for="report-decision">Decision <x-required-marker /><select id="report-decision" name="decision" required @if ($errors->has('decision')) aria-invalid="true" aria-describedby="report-decision-error" @endif class="mt-1 block min-h-11 w-full rounded-md border bg-[var(--bg-surface)] p-2"><option value="">Choose a decision</option>@foreach (\App\Models\Shared\BuyerConductReport::DECISIONS as $value => $label)<option value="{{ $value }}" @selected(old('decision') === $value)>{{ $label }}</option>@endforeach</select></label>
                <x-field-error id="report-decision-error" :messages="$errors->get('decision')" />
                <label class="block" for="report-decision-note">Decision and reasons (10–2000 characters) <x-required-marker /><textarea id="report-decision-note" name="decision_note" required minlength="10" maxlength="2000" rows="4" @if ($errors->has('decision_note')) aria-invalid="true" aria-describedby="report-decision-note-error" @endif class="mt-1 block w-full rounded-md border bg-[var(--bg-surface)] p-2">{{ old('decision_note') }}</textarea></label>
                <x-field-error id="report-decision-note-error" :messages="$errors->get('decision_note')" />
                <button type="submit" class="min-h-11 rounded-lg bg-primary-800 px-4 py-2 font-semibold text-white hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800 disabled:cursor-wait disabled:opacity-60">Record review</button>
            </form>
        @else
            <p class="mt-3">{{ \App\Models\Shared\BuyerConductReport::DECISIONS[$report->status] ?? 'Reviewed' }} · Admin #{{ $report->reviewed_by }} · {{ manila($report->reviewed_at, 'M j, Y g:i A') }}</p>
            <p class="mt-2 whitespace-pre-wrap break-words">{{ $report->decision_note }}</p>
        @endif
    </section>
@endsection
