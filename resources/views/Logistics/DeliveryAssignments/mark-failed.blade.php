@extends('Layouts.logistics')

@section('title', 'Mark Delivery Failed')

@use('App\Enums\OrderStatus')

@section('content')
<div class="flex items-center justify-between mb-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Mark Delivery Failed</h1>
</div>

<x-logistics-flash :messages="['success', 'warning', 'error']" />

<div class="bg-[var(--bg-surface)] border border-[var(--border-strong)] rounded-lg shadow-sm p-6 max-w-2xl">
    <div class="mb-6">
        <p class="font-sans text-[13px] font-semibold text-[var(--text-ink)] mb-1">Parcel #{{ $sellerOrder->id }}</p>
        @php($buyer = $sellerOrder->order?->buyer)
        @php($addr = $sellerOrder->order?->shipping_address ?? [])
        <p class="font-sans text-[12.5px] text-[var(--text-muted)]">{{ $buyer?->fullName() ?? '—' }} · {{ $addr['municipality'] ?? '—' }}</p>
    </div>

    <form method="POST" action="{{ route('logistics.delivery-assignments.failed', $sellerOrder) }}">
        @csrf

        <div class="mb-5">
            <label for="failure_reason" class="block font-sans font-semibold text-[12.5px] text-[var(--text-ink)] mb-2">
                Failure Reason <span class="text-rose-600">*</span>
            </label>
            <select name="failure_reason" id="failure_reason"
                    class="w-full px-3 py-2.5 border border-[var(--border-strong)] rounded-sm font-sans text-[13px] text-[var(--text-ink)] bg-[var(--bg-page)] focus:border-primary-400 focus:outline-none"
                    required>
                <option value="" disabled selected>Select a reason</option>
                @foreach ($reasons as $reason)
                    <option value="{{ $reason->value }}">{{ $reason->label() }}</option>
                @endforeach
            </select>
            @error('failure_reason')
                <p class="mt-1.5 font-sans text-[11.5px] text-rose-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="mb-5" id="notesField" style="display: none;">
            <label for="failure_notes" class="block font-sans font-semibold text-[12.5px] text-[var(--text-ink)] mb-2">
                Notes <span class="text-rose-600" id="notesRequired">*</span>
            </label>
            <textarea name="failure_notes" id="failure_notes" rows="4"
                      class="w-full px-3 py-2.5 border border-[var(--border-strong)] rounded-sm font-sans text-[13px] text-[var(--text-ink)] bg-[var(--bg-page)] focus:border-primary-400 focus:outline-none resize-y"
                      maxlength="1000"
                      @error('failure_notes') aria-invalid="true" @enderror></textarea>
            @error('failure_notes')
                <p class="mt-1.5 font-sans text-[11.5px] text-rose-600">{{ $message }}</p>
            @enderror
            <p class="mt-1 font-sans text-[11px] text-[var(--text-muted)]">Max 1000 characters</p>
        </div>

        <div class="flex gap-3">
            <button type="submit"
                    class="inline-flex items-center justify-center h-10 px-5 rounded-sm font-sans font-semibold text-[12.5px] tracking-[0.5px] uppercase text-white bg-rose-700 hover:bg-rose-800">
                <i data-lucide="x" width="16" height="16" class="mr-2"></i>
                Mark as Failed
            </button>
            <a href="{{ route('logistics.delivery-assignments.index') }}"
               class="inline-flex items-center justify-center h-10 px-5 rounded-sm font-sans font-semibold text-[12.5px] tracking-[0.5px] uppercase text-white bg-neutral-500 hover:bg-neutral-600">
                Cancel
            </a>
        </div>
    </form>
</div>
@endsection

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const reasonSelect = document.getElementById('failure_reason');
        const notesField = document.getElementById('notesField');
        const notesRequired = document.getElementById('notesRequired');

        function toggleNotes() {
            if (reasonSelect.value === 'other') {
                notesField.style.display = 'block';
                notesRequired.style.display = 'inline';
            } else {
                notesField.style.display = 'none';
                notesRequired.style.display = 'none';
            }
        }

        reasonSelect.addEventListener('change', toggleNotes);
        toggleNotes();
    });
</script>
@endpush