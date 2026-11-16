@extends($layout)
@section('title', 'Warranty / Consumer Claim')
@section('content')
<div class="mx-auto max-w-3xl p-4 sm:p-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Warranty / Consumer Claim</h1>
    <p class="mt-2 text-sm text-[var(--text-muted)]">Shipment #{{ $shipment->id }} · {{ $shipment->order->reference }}. This manual review pathway is separate from ordinary seven-day returns. The standard return window does not determine the validity of a warranty or consumer remedy. The applicable terms and circumstances will be reviewed.</p>
    <x-form-errors :show="$errors->any()" :messages="$errors->all()" />
    
@php($field = 'mt-2 block w-full rounded-md border border-[var(--border-input)] bg-[var(--bg-surface)] p-3 text-sm text-[var(--text-ink)] focus-visible:outline-2 focus-visible:outline-[var(--brand)]')
    <form class="mt-6 space-y-5 rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-5" method="POST" action="{{ route('buyer.consumer-claims.store', $shipment->id) }}" enctype="multipart/form-data">
        
@csrf
        <input type="hidden" name="submission_key" value="{{ old('submission_key', (string) \Illuminate\Support\Str::uuid()) }}">
        <label class="block font-semibold">Affected product<select name="order_item_id" required class="{{ $field }}">
<option value="" disabled @selected(!old('order_item_id'))>Choose the affected product</option>
@foreach($shipment->orderItems as $item)<option value="{{ $item->id }}" @selected(old('order_item_id') == $item->id)>{{ $item->product_name }}</option>
@endforeach</select></label>
        <label class="block font-semibold">Claim basis<select name="basis" required class="{{ $field }}">
<option value="" disabled @selected(!old('basis'))>Choose the claim basis</option>
@foreach(\App\Services\Buyer\ConsumerClaimService::BASES as $basis)<option value="{{ $basis }}" @selected(old('basis') === $basis)>{{ str_replace('_', ' ', ucfirst($basis)) }}</option>
@endforeach</select></label>
        <label class="block font-semibold">Category<select name="category" required class="{{ $field }}">
<option value="" disabled @selected(!old('category'))>Choose a category</option>
@foreach(\App\Services\Buyer\ConsumerClaimService::CATEGORIES as $category)<option value="{{ $category }}" @selected(old('category') === $category)>{{ str_replace('_', ' ', ucfirst($category)) }}</option>
@endforeach</select></label>
        <label class="block font-semibold">Describe the issue<textarea name="description" required minlength="10" maxlength="5000" rows="5" class="{{ $field }}">{{ old('description') }}</textarea></label>
        <label class="block font-semibold">Relevant warranty terms or service history (optional)<textarea name="warranty_details" maxlength="2000" rows="3" class="{{ $field }}">{{ old('warranty_details') }}</textarea></label>
        <label class="block font-semibold">Supporting evidence (optional)<input type="file" name="evidence[]" multiple accept="image/jpeg,image/png,application/pdf" class="{{ $field }}"><span class="mt-2 block text-xs font-normal text-[var(--text-muted)]">Up to five JPG, PNG or PDF files, 5 MB each. Include warranty documents, receipts or photos if available. Share only information relevant to this case.</span></label>
        <p class="text-sm text-[var(--text-muted)]">Submission does not automatically authorize a refund, charge the Seller, restock inventory or arrange shipment. Admin will explain any approved next steps.</p>
        <button class="min-h-11 rounded-md bg-[var(--btn-accent)] px-5 font-semibold text-[var(--btn-accent-contrast)]" type="submit">Submit for review</button>
    </form>
</div>
@endsection
