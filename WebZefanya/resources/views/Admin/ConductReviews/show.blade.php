@extends('Admin.Layouts.app')
@section('title', 'Conduct review '.$review->reference())
@section('content')
    @php($card = 'mt-4 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5 text-sm text-[var(--text-ink)]')
    @php($evidence = $review->evidence ?? [])
    <a class="text-sm font-semibold text-primary-800 underline" href="{{ route('admin.conduct-reviews.index') }}">Back to conduct reviews</a>
    <h1 class="mt-4 text-2xl font-semibold text-[var(--text-ink)]">Conduct review {{ $review->reference() }}</h1>
    <p class="mt-1 text-sm text-[var(--text-muted)]">{{ $review->categoryLabel() }} · {{ $review->statusLabel() }} · Raised {{ manila($review->created_at, 'M j, Y g:i A') }}</p>
    <x-logistics-flash :messages="['success', 'warning', 'error']" />
    <x-form-errors :show="$errors->any()" :messages="collect($errors->getMessages())->except(['decision', 'decision_reason'])->flatten()->all()" />

    <section class="{{ $card }}" aria-labelledby="buyer-heading">
        <h2 id="buyer-heading" class="font-semibold">Buyer</h2>
        <p class="mt-2">Buyer #{{ $review->buyer_id }} · {{ $review->buyer?->fullName() ?? 'Account removed' }} · {{ $review->buyer?->email }}</p>
        <p class="mt-1">Account status: <span class="font-semibold">{{ ucfirst((string) ($review->buyer?->status ?? 'unknown')) }}</span>
            @if ($codStrikes !== null) · COD delivery strikes: {{ $codStrikes }} of {{ $codStrikeLimit }} (managed separately in Accounts) @endif
        </p>
    </section>

    <section class="{{ $card }}" aria-labelledby="trigger-heading">
        <h2 id="trigger-heading" class="font-semibold">Why this case exists</h2>
        @if ($review->category === \App\Models\Shared\BuyerConductReview::CATEGORY_SELLER_REPORT && $review->report)
            @php($report = $review->report)
            <p class="mt-2">Seller report <a class="text-primary-800 underline" href="{{ route('admin.buyer-reports.show', $report) }}">#{{ $report->id }}</a> · Order {{ $report->sellerOrder?->order?->reference }} · shipment #{{ $report->seller_order_id }} · Seller: {{ $report->seller?->business_name ?? 'Seller' }}</p>
            <p class="mt-2 font-semibold">{{ \App\Models\Shared\BuyerConductReport::REASONS[$report->reason] ?? 'Concern' }}</p>
            <p class="mt-2 whitespace-pre-wrap break-words">{{ $report->description }}</p>
            @foreach ($report->evidence ?? [] as $index => $path)
                <a class="mt-3 mr-2 inline-block text-primary-800 underline" href="{{ route('admin.buyer-reports.evidence', [$report, $index]) }}" target="_blank" rel="noopener">View private evidence {{ $index + 1 }}</a>
            @endforeach
            <p class="mt-3 text-xs text-[var(--text-muted)]">A Seller report is an allegation. It does not establish misconduct until reviewed.</p>
        @else
            <p class="mt-2">{{ $review->qualifying_count }} qualifying {{ $review->category === 'cancellations' ? 'Buyer-initiated cancelled orders' : 'return rejections upheld by Admin after a dispute' }}
                between {{ manila($review->window_started_at, 'M j, Y') }} and {{ manila($review->window_ended_at, 'M j, Y') }}.</p>
            <p class="mt-2 text-xs text-[var(--text-muted)]">
                @if ($review->category === 'cancellations')
                    Counts distinct orders cancelled by the Buyer within {{ \App\Services\Admin\BuyerConductReviewService::CANCELLATION_WINDOW_DAYS }} days. Seller and system cancellations and legacy records without a verified actor are excluded. Cancelling is a Buyer right before Seller acceptance; consider delays and Seller issues.
                @else
                    Counts the Buyer's own return requests that the Seller rejected, the Buyer disputed, and Admin then upheld. A Seller rejection on its own, pending or overturned disputes, withdrawn requests, approved returns and receipt disputes are excluded.
                @endif
                Activity already covered by an earlier resolved case is not counted again.
            </p>
        @endif
    </section>

    <section class="{{ $card }}" aria-labelledby="cancel-heading">
        <h2 id="cancel-heading" class="font-semibold">Buyer-initiated cancellations (last {{ \App\Services\Admin\BuyerConductReviewService::RETURN_WINDOW_DAYS }} days) · {{ $history['cancellationOrders'] }} distinct orders in all recorded history</h2>
        <ul class="mt-3 space-y-2">
            @forelse ($history['cancellations'] as $row)
                <li class="rounded-md border border-[var(--border-strong)] p-3 @if (in_array($row->id, $evidence['status_history_ids'] ?? [], true)) border-primary-800 @endif">
                    {{ $row->sellerOrder?->order?->reference }} · shipment #{{ $row->seller_order_id }} · {{ manila($row->created_at, 'M j, Y g:i A') }}
                    @if (in_array($row->id, $evidence['status_history_ids'] ?? [], true)) <span class="ml-1 text-xs font-semibold text-primary-800">Counted in this case</span> @endif
                    <p class="mt-1 whitespace-pre-wrap break-words text-[var(--text-muted)]">{{ $row->note ?: 'No reason recorded.' }}</p>
                </li>
            @empty
                <li>No Buyer-initiated cancellations in this period.</li>
            @endforelse
        </ul>
    </section>

    <section class="{{ $card }}" aria-labelledby="returns-heading">
        <h2 id="returns-heading" class="font-semibold">Return requests</h2>
        <ul class="mt-3 space-y-2">
            @forelse ($history['returns'] as $return)
                <li class="rounded-md border border-[var(--border-strong)] p-3 @if (in_array($return->id, $evidence['parcel_return_ids'] ?? [], true)) border-primary-800 @endif">
                    Return #{{ $return->id }} · {{ $return->sellerOrder?->order?->reference }} · requested {{ manila($return->requested_at ?? $return->created_at, 'M j, Y') }}
                    · Seller: {{ $return->seller_decision ? ucfirst(str_replace('_', ' ', $return->seller_decision)) : 'no decision yet' }}
                    @if ($return->dispute_outcome) · Dispute: {{ $return->dispute_outcome === \App\Models\Logistics\ParcelReturn::DISPUTE_UPHELD ? 'rejection upheld' : 'rejection overturned' }} {{ manila($return->dispute_resolved_at, 'M j, Y') }}
                    @elseif ($return->inDispute()) · Dispute pending @endif
                    · Status: {{ $return->status?->value ?? $return->status }}
                    @if (in_array($return->id, $evidence['parcel_return_ids'] ?? [], true)) <span class="ml-1 text-xs font-semibold text-primary-800">Counted in this case</span> @endif
                    <p class="mt-1 text-[var(--text-muted)]">{{ $return->reason }}</p>
                </li>
            @empty
                <li>No return requests recorded for this Buyer.</li>
            @endforelse
        </ul>
    </section>

    <section class="{{ $card }}" aria-labelledby="reports-heading">
        <h2 id="reports-heading" class="font-semibold">Seller reports about this Buyer</h2>
        <ul class="mt-3 space-y-2">
            @forelse ($history['reports'] as $row)
                <li class="rounded-md border border-[var(--border-strong)] p-3"><a class="text-primary-800 underline" href="{{ route('admin.buyer-reports.show', $row) }}">Report #{{ $row->id }}</a> · {{ $row->seller?->business_name ?? 'Seller' }} · {{ \App\Models\Shared\BuyerConductReport::REASONS[$row->reason] ?? 'Concern' }} · {{ $row->status === 'open' ? 'Unresolved' : (\App\Models\Shared\BuyerConductReport::DECISIONS[$row->status] ?? 'Reviewed') }} · {{ manila($row->created_at, 'M j, Y') }}</li>
            @empty
                <li>No Seller reports.</li>
            @endforelse
        </ul>
    </section>

    <section class="{{ $card }}" aria-labelledby="past-heading">
        <h2 id="past-heading" class="font-semibold">Other conduct reviews for this Buyer</h2>
        <ul class="mt-3 space-y-2">
            @forelse ($history['reviews'] as $row)
                <li class="rounded-md border border-[var(--border-strong)] p-3"><a class="text-primary-800 underline" href="{{ route('admin.conduct-reviews.show', $row->id) }}">{{ $row->reference() }}</a> · {{ $row->categoryLabel() }} · {{ $row->statusLabel() }}@if ($row->decision) · {{ $row->decisionLabel() }} · {{ $row->decider?->name ?? 'Admin #'.$row->decided_by }} · {{ manila($row->decided_at, 'M j, Y g:i A') }}@endif</li>
            @empty
                <li>No other reviews.</li>
            @endforelse
        </ul>
    </section>

    <section class="{{ $card }}" aria-labelledby="decision-heading">
        <h2 id="decision-heading" class="font-semibold">Decision</h2>
        @if ($review->isResolved())
            <p class="mt-2"><span class="font-semibold">{{ $review->decisionLabel() }}</span> · {{ $review->decider?->name ?? 'Admin #'.$review->decided_by }} · {{ manila($review->decided_at, 'M j, Y g:i A') }}</p>
            <p class="mt-1">Account status: {{ $review->account_status_before ?? 'unknown' }} → {{ $review->account_status_after ?? 'unknown' }}</p>
            @if ($review->decision_reason)<p class="mt-2 whitespace-pre-wrap break-words">{{ $review->decision_reason }}</p>@endif
            <p class="mt-2 text-xs text-[var(--text-muted)]">Buyer notified: {{ $review->buyer_notified_at ? manila($review->buyer_notified_at, 'M j, Y g:i A') : 'No' }}@if ($review->buyer_conduct_report_id) · Reporting Seller notified: {{ $review->seller_resolved_notified_at ? manila($review->seller_resolved_notified_at, 'M j, Y g:i A') : 'No' }}@endif</p>
            <p class="mt-2 text-xs text-[var(--text-muted)]">Decisions are final. To lift a suspension, use the existing Reactivate action in Accounts.</p>
        @else
            @if ($review->status === 'pending')
                <form method="POST" action="{{ route('admin.conduct-reviews.start', $review->id) }}" class="mt-3" data-submit-guard>
                    @csrf
                    <button type="submit" class="min-h-11 rounded-lg border border-primary-800 px-4 py-2 font-semibold text-primary-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800 disabled:cursor-wait disabled:opacity-60">Start review</button>
                    <p class="mt-1 text-xs text-[var(--text-muted)]">Marks the case as in review.@if ($review->buyer_conduct_report_id) The reporting Seller is told the report is in review.@endif The Buyer is not notified.</p>
                </form>
            @else
                <p class="mt-2 text-xs text-[var(--text-muted)]">In review since {{ manila($review->review_started_at, 'M j, Y g:i A') }}.</p>
            @endif
            <form method="POST" action="{{ route('admin.conduct-reviews.decide', $review->id) }}" class="mt-4 space-y-3" data-submit-guard>
                @csrf
                <fieldset>
                    <legend class="font-semibold">Choose a decision <x-required-marker /></legend>
                    <div class="mt-2 space-y-2">
                        @foreach (\App\Models\Shared\BuyerConductReview::DECISIONS as $value => $label)
                            <label class="flex items-start gap-2">
                                <input type="radio" name="decision" value="{{ $value }}" required class="mt-1" @checked(old('decision') === $value) @if ($errors->has('decision')) aria-invalid="true" aria-describedby="review-decision-error" @endif>
                                <span><span class="font-semibold">{{ $label }}</span>
                                    <span class="block text-xs text-[var(--text-muted)]">{{ match ($value) {
                                        'no_action' => 'Close the case. The Buyer is not notified.',
                                        'advisory' => 'Send the Buyer a neutral in-app advisory. The account stays active.',
                                        'formal_warning' => 'Send the Buyer a formal in-app warning. The account stays active. Justification required.',
                                        'account_restriction' => 'Suspend the account using the existing Accounts suspension (sessions ended, email sent). No automatic end date; reactivate from Accounts. Justification required.',
                                        'closed_duplicate' => 'Close as a duplicate of another case. The Buyer is not notified.',
                                        'closed_unsubstantiated' => 'Close because the concern was not substantiated. The Buyer is not notified.',
                                    } }}</span></span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>
                <x-field-error id="review-decision-error" :messages="$errors->get('decision')" />
                <label class="block" for="review-decision-reason">Reason (required for Formal warning and Account restriction; 10–2000 characters)
                    <textarea id="review-decision-reason" name="decision_reason" minlength="10" maxlength="2000" rows="4" @if ($errors->has('decision_reason')) aria-invalid="true" aria-describedby="review-decision-reason-error" @endif class="mt-1 block w-full rounded-md border bg-[var(--bg-surface)] p-2">{{ old('decision_reason') }}</textarea>
                </label>
                <p class="text-xs text-[var(--text-muted)]">For Advisory, Formal warning and Account restriction this reason is shown to the Buyer. Keep it factual; do not include other users' details, Seller identities or unproven allegations.</p>
                <x-field-error id="review-decision-reason-error" :messages="$errors->get('decision_reason')" />
                <button type="submit" class="min-h-11 rounded-lg bg-primary-800 px-4 py-2 font-semibold text-white hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800 disabled:cursor-wait disabled:opacity-60">Record decision</button>
            </form>
        @endif
    </section>

    <section class="{{ $card }}" aria-labelledby="audit-heading">
        <h2 id="audit-heading" class="font-semibold">Audit history</h2>
        <ul class="mt-3 space-y-2">
            @forelse ($history['audit'] as $log)
                <li class="rounded-md border border-[var(--border-strong)] p-3">{{ $log->actionLabel() }} · {{ $log->actor_name }} · {{ manila($log->created_at, 'M j, Y g:i A') }}
                    @foreach ($log->changes() as $change)<span class="block text-xs text-[var(--text-muted)]">{{ $change['field'] }}: {{ $change['old'] ?? '—' }} → {{ $change['new'] ?? '—' }}</span>@endforeach
                    @if ($log->reason)<p class="mt-1 whitespace-pre-wrap break-words">{{ $log->reason }}</p>@endif
                </li>
            @empty
                <li>No actions recorded yet.</li>
            @endforelse
        </ul>
    </section>
@endsection
