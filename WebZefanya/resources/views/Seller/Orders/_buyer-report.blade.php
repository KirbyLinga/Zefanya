@php($reportFormFailed = old('_validation_form') === 'seller-buyer-report-'.$sellerOrder->id)
@if (\App\Models\Shared\BuyerConductReport::available())
    <section class="mt-5 rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5" aria-labelledby="buyer-report-heading">
        <h2 id="buyer-report-heading" class="text-sm font-semibold text-[var(--text-ink)]">Report Buyer</h2>
        <p class="mt-2 text-xs leading-relaxed text-[var(--text-muted)]">Report a concern about this order for Admin review. Reporting does not apply a penalty. Include only information relevant to the concern; do not upload identity documents or payment credentials.</p>
        @if ($buyerReports->contains('status', 'open'))
            <p class="mt-3 text-sm text-[var(--text-ink)]" role="status">An unresolved report already exists for this order. Admin will review it; another submission is not needed.</p>
        @else
            <details class="mt-3" @if ($reportFormFailed && $errors->any()) open @endif>
                <summary class="cursor-pointer text-sm font-semibold text-primary-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800">Report Buyer for this order</summary>
                <form method="POST" action="{{ route('seller.orders.report-buyer', $sellerOrder) }}" enctype="multipart/form-data" class="mt-4 space-y-3" data-submit-guard>
                    @csrf
                    <input type="hidden" name="_validation_form" value="seller-buyer-report-{{ $sellerOrder->id }}">
                    @if ($reportFormFailed)<x-form-errors :show="$errors->any()" :messages="collect($errors->getMessages())->reject(fn ($messages, $key) => in_array($key, ['reason', 'description', 'evidence'], true) || str_starts_with($key, 'evidence.'))->flatten()->all()" />@endif
                    <label class="block text-sm text-[var(--text-ink)]" for="buyer-report-reason">Reason <x-required-marker />
                        <select id="buyer-report-reason" name="reason" required @if ($reportFormFailed && $errors->has('reason')) aria-invalid="true" aria-describedby="buyer-report-reason-error" @endif class="mt-1 block min-h-11 w-full rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2">
                            <option value="">Choose a reason</option>
                            @foreach (\App\Models\Shared\BuyerConductReport::REASONS as $value => $label)
                                <option value="{{ $value }}" @selected($reportFormFailed && old('reason') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if ($reportFormFailed)<x-field-error id="buyer-report-reason-error" :messages="$errors->get('reason')" />@endif
                    <label class="block text-sm text-[var(--text-ink)]" for="buyer-report-description">Description (10–2000 characters) <x-required-marker />
                        <textarea id="buyer-report-description" name="description" rows="4" required minlength="10" maxlength="2000" @if ($reportFormFailed && $errors->has('description')) aria-invalid="true" aria-describedby="buyer-report-description-error" @endif class="mt-1 block w-full rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2">{{ $reportFormFailed ? old('description') : '' }}</textarea>
                    </label>
                    @if ($reportFormFailed)<x-field-error id="buyer-report-description-error" :messages="$errors->get('description')" />@endif
                    <label class="block text-sm text-[var(--text-ink)]" for="buyer-report-evidence">Evidence (optional; up to 3 JPG or PNG images, 5 MB each)
                        <input id="buyer-report-evidence" type="file" name="evidence[]" @if ($reportFormFailed && ($errors->has('evidence') || $errors->has('evidence.*'))) aria-invalid="true" aria-describedby="buyer-report-evidence-error" @endif accept="image/jpeg,image/png" multiple class="mt-2 block w-full text-xs">
                    </label>
                    @if ($reportFormFailed)
                        <x-field-error id="buyer-report-evidence-error" :messages="[$errors->get('evidence'), $errors->get('evidence.*')]" />
                        <p class="text-xs text-[var(--text-muted)]">Please select any evidence photos again after a failed submission.</p>
                    @endif
                    <button type="submit" class="min-h-11 rounded-lg bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-800 disabled:cursor-wait disabled:opacity-60">Submit report for review</button>
                </form>
            </details>
        @endif
        @if ($buyerReports->isNotEmpty())
            <details class="mt-4">
                <summary class="cursor-pointer text-sm font-semibold text-primary-800">Your report history for this order</summary>
                <ul class="mt-2 space-y-2 text-xs text-[var(--text-muted)]">
                    @foreach ($buyerReports as $report)
                        <li class="rounded-md border border-[var(--border-strong)] p-3">Report #{{ $report->id }} · {{ \App\Models\Shared\BuyerConductReport::REASONS[$report->reason] ?? 'Concern' }} · {{ $report->sellerStatusLabel() }} · {{ manila($report->created_at, 'M j, Y g:i A') }}</li>
                    @endforeach
                </ul>
            </details>
        @endif
    </section>
@else
    <p class="mt-4 text-xs text-[var(--text-muted)]">Report Buyer will be available after the reporting database update. Contact support about this order in the meantime.</p>
@endif
