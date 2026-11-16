@php
    $authorization = $record->currentRefundAuthorization();
    $submission = $record->currentRefundSubmission();
@endphp
<div class="mt-3 space-y-3 rounded-md border border-[var(--border-strong)] p-3 text-xs text-[var(--text-ink)]">
    <p class="font-semibold">{{ $record->refundPaymentLabel(internal: true) }}</p>
    @php($actors = $record->refundStepActors())
    @foreach (['authorization' => 'Authorized', 'evidence' => 'Evidence submitted', 'confirmation' => 'Confirmed'] as $key => $label)
        @if ($actors[$key])<p class="text-[var(--text-muted)]">{{ $label }} by Admin #{{ $actors[$key]['admin_id'] }} at {{ $actors[$key]['at']->timezone('Asia/Manila')->format('M j, Y g:i A') }}</p>@endif
    @endforeach
    @if ($record->refundHandledByOneAdmin(auth('admin')->id()))
        <p class="rounded-sm border border-amber-700 p-2 font-semibold text-amber-900">Notice: one Admin has performed or will perform every step. This is allowed, but it is a single-person review and not independent verification of the bank transfer.</p>
    @endif
    <p class="text-[var(--text-muted)]">Return approval does not confirm payment. Pending COD remittance remains owed separately.</p>
    @if (! $authorization)
        <form method="POST" action="{{ route('admin.returns.refund.authorize', $record) }}" enctype="multipart/form-data" class="grid gap-3 sm:grid-cols-2">
            @csrf
            <label>Authorize amount (PHP)<input name="amount" inputmode="decimal" required value="{{ old('amount') }}" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2"></label>
            <label>Who pays<select name="payer" required class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2">
                <option value="">Confirm payer</option>
                @foreach (['seller' => 'Seller', 'logistics' => 'Logistics', 'platform' => 'Zefanya (platform exception)'] as $payer => $label)
                    <option value="{{ $payer }}" @selected(old('payer') === $payer)>{{ $label }}</option>
                @endforeach
            </select></label>
            <label class="sm:col-span-2">Authorization and payer justification (10-1000 characters)<textarea name="authorization_note" required minlength="10" maxlength="1000" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2">{{ old('authorization_note') }}</textarea></label>
            @if ($blocker = $record->refundNormalPathBlocker())
                <fieldset class="space-y-3 rounded-sm border border-amber-700 p-3 sm:col-span-2">
                    <legend class="px-1 font-semibold">Exception under review</legend>
                    <p class="font-semibold">Normal path closed: {{ $blocker }}</p>
                    <p>Explain why receipt cannot reasonably be awaited or why a different remedy is required. Approval is never automatic.</p>
                    <label class="block">Exception basis<select name="refund_exception_basis" required class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2">
                        <option value="">Choose documented basis</option>
                        @foreach (\App\Models\Logistics\ParcelReturn::REFUND_EXCEPTION_BASES as $basis => $label)<option value="{{ $basis }}">{{ $label }}</option>@endforeach
                    </select></label>
                    <label class="block">Supporting exception evidence (JPG, PNG or PDF, up to 5 MB)<input type="file" name="exception_proof" required accept=".jpg,.jpeg,.png,.pdf" class="upload-input"></label>
                </fieldset>
            @endif
            <button class="rounded-sm bg-primary-700 p-2 font-semibold text-white sm:col-span-2" type="submit">Authorize refund - payment remains pending</button>
        </form>
    @else
        <p>Authorized PHP {{ number_format($authorization->metadata['amount_minor'] / 100, 2) }}; payer: {{ $authorization->metadata['payer'] }}. {{ $authorization->metadata['note'] }}</p>
        @if ($authorization->metadata['exception_proof_path'])<a class="font-semibold text-primary-800 underline" href="{{ route('admin.returns.refund.evidence-file', [$record, $authorization->id]) }}" target="_blank" rel="noopener">Review exception evidence</a>@endif
        @if ($submission)
            <div class="space-y-2 rounded-sm border border-[var(--border-strong)] p-3">
                <p class="font-semibold">Payment evidence submitted - not yet verified</p>
                <p>PHP {{ number_format($submission->metadata['amount_minor'] / 100, 2) }} to {{ $submission->metadata['recipient'] }}; paid {{ $submission->metadata['paid_on'] }}; reference {{ $submission->metadata['reference'] }}.</p>
                <a class="font-semibold text-primary-800 underline" href="{{ route('admin.returns.refund.evidence-file', [$record, $submission->id]) }}" target="_blank" rel="noopener">Review private payment evidence</a>
                <form method="POST" action="{{ route('admin.returns.refund.confirm', $record) }}" class="space-y-2" data-app-confirm data-confirm-title="Confirm refund payment" data-confirm-label="Confirm Payment" data-confirm-message="Confirm only after reviewing actual external payment evidence. A typed reference alone does not verify payment.">
                    @csrf
                    <input type="hidden" name="evidence_id" value="{{ $submission->id }}">
                    <label class="block">How was payment verified? (10-1000 characters)<textarea name="verification_note" required minlength="10" maxlength="1000" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2"></textarea></label>
                    <p class="text-[var(--text-muted)]">This records an Admin evidence review, not automatic bank verification.</p>
                    <button class="rounded-sm bg-primary-700 p-2 font-semibold text-white" type="submit">Confirm refund payment</button>
                </form>
            </div>
        @endif
        <details @if (! $submission) open @endif>
            <summary class="cursor-pointer font-semibold">{{ $submission ? 'Replace submitted evidence before confirmation' : 'Submit external payment evidence' }}</summary>
            <form method="POST" action="{{ route('admin.returns.refund.evidence', $record) }}" enctype="multipart/form-data" class="mt-3 grid gap-3 sm:grid-cols-2">
                @csrf
                <input type="hidden" name="authorization_id" value="{{ $authorization->id }}">
                <label>Actual amount paid (PHP)<input name="amount" required inputmode="decimal" value="{{ number_format($authorization->metadata['amount_minor'] / 100, 2, '.', '') }}" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2"></label>
                <label>Actual payer<select name="payer" required class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2">
                    <option value="">Confirm who paid</option>
                    @foreach (['seller' => 'Seller', 'logistics' => 'Logistics', 'platform' => 'Zefanya'] as $payer => $label)<option value="{{ $payer }}">{{ $label }}</option>@endforeach
                </select></label>
                <label>Actual payment reference<input name="reference" required maxlength="255" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2"></label>
                <label>Recipient (name and account)<input name="recipient" required maxlength="255" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2"></label>
                <label>Date paid<input type="date" name="paid_on" required max="{{ now('Asia/Manila')->toDateString() }}" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2"></label>
                <label>Proof (JPG, PNG or PDF up to 5 MB)<input type="file" name="proof" required accept=".jpg,.jpeg,.png,.pdf" class="upload-input"></label>
                <label class="sm:col-span-2">Final partial-refund explanation, if less than collected COD<textarea name="refund_reason" maxlength="1000" class="mt-1 block w-full rounded-sm border border-[var(--border-strong)] p-2"></textarea></label>
                <button class="rounded-sm border border-primary-700 p-2 font-semibold text-primary-800 sm:col-span-2" type="submit">Submit evidence - await verification</button>
            </form>
        </details>
    @endif
</div>
