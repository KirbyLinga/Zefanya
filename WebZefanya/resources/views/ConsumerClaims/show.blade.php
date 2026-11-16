@extends($layout)
@section('title', 'Consumer Claim #'.$claim->id)
@section('content')
<div class="mx-auto max-w-5xl space-y-6 p-4 sm:p-6">
    <header><a href="{{ route($role.'.consumer-claims.index') }}" class="text-sm text-[var(--link)] underline">Back to claims</a><h1 class="mt-3 font-serif text-2xl text-[var(--text-ink)]">Warranty / Consumer Claim #{{ $claim->id }}</h1><p class="mt-2 text-sm text-[var(--text-muted)]">Manual review · {{ str_replace('_', ' ', $claim->status) }}</p></header>
    <x-flash type="success" :message="session('success')" />
    <x-form-errors :show="$errors->any()" :messages="$errors->all()" />
    <section class="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-5 text-sm text-[var(--text-ink)]">
        <h2 class="font-semibold">{{ $claim->product_name }}</h2><dl class="mt-3 space-y-2"><div><dt class="font-semibold">Order / shipment</dt><dd>{{ $claim->shipment?->order?->reference }} · #{{ $claim->seller_order_id }} · {{ $claim->shipment?->tracking_number }}</dd></div><div><dt class="font-semibold">Claim basis</dt><dd>{{ str_replace('_', ' ', $claim->basis) }} · {{ str_replace('_', ' ', $claim->category) }}</dd></div><div><dt class="font-semibold">Warranty information supplied</dt><dd class="whitespace-pre-wrap">{{ $claim->warranty_details ?: 'No warranty terms supplied. Admin may request more information.' }}</dd></div></dl>
        @if($role === 'admin')
            <a href="{{ route('admin.orders.show', $claim->seller_order_id) }}" class="mt-3 inline-flex min-h-11 items-center font-semibold text-[var(--link)] underline">Inspect order, product and shipment details</a>
        @endif
        <details class="mt-4"><summary class="cursor-pointer font-semibold">Recorded delivery context</summary><ul class="mt-2 space-y-1">
@foreach($claim->shipment?->statusHistories ?? [] as $history)
@if($history->to_status === \App\Enums\OrderStatus::Delivered)<li>Delivered · {{ $history->created_at?->format('M j, Y g:i A') }}</li>
@endif
@endforeach</ul></details>
        <p class="mt-4 text-[var(--text-muted)]">An approval here records a review decision. Any repair, replacement, refund or shipment action needs separately documented handling through the authorized workflow. No money or inventory action occurs here.</p>
    </section>
    <section aria-labelledby="claim-history-title"><h2 id="claim-history-title" class="font-semibold text-[var(--text-ink)]">Case history</h2><ol class="mt-3 space-y-3">
@foreach($claim->updates as $update)<li class="rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-4 text-sm text-[var(--text-ink)]"><p class="font-semibold">{{ ucfirst($update->actor_type) }} · {{ str_replace('_', ' ', $update->action) }} · {{ $update->created_at?->format('M j, Y g:i A') }}</p><p class="mt-2 whitespace-pre-wrap">{{ $update->message }}</p>
@foreach($update->evidence ?? [] as $index => $file)<a class="mt-2 block text-[var(--link)] underline" href="{{ route($role.'.consumer-claims.evidence', [$claim->id, $update->id, $index]) }}">Download {{ $file['name'] }}</a>
@endforeach</li>
@endforeach</ol></section>
    
@if($claim->status !== 'closed' && ($role !== 'seller' || $claim->status === 'awaiting_seller'))
    <form method="POST" action="{{ route($role.'.consumer-claims.update', $claim->id) }}" enctype="multipart/form-data" class="space-y-4 rounded-lg border border-[var(--border)] bg-[var(--bg-surface)] p-5 text-[var(--text-ink)]">
        
@csrf
        <input type="hidden" name="revision" value="{{ $claim->revision }}"><input type="hidden" name="submission_key" value="{{ old('submission_key', (string) \Illuminate\Support\Str::uuid()) }}">
        <h2 class="font-semibold">{{ $role === 'admin' ? 'Admin review decision' : 'Provide a response' }}</h2>
        
@if($role === 'admin')<label class="block text-sm font-semibold">Action<select required name="action" class="mt-2 block min-h-11 w-full rounded-md border border-[var(--border-input)] bg-[var(--bg-surface)] p-2">
<option value="" disabled @selected(!old('action'))>Choose an action</option>
@foreach(\App\Services\Buyer\ConsumerClaimService::DECISIONS as $action)<option value="{{ $action }}" @selected(old('action') === $action)>{{ ucfirst(str_replace('_', ' ', $action)) }}</option>
@endforeach</select></label>
@else<input type="hidden" name="action" value="response">
@endif
        <label class="block text-sm font-semibold">{{ $role === 'admin' ? 'Documented justification / next steps (required; visible to case participants)' : 'Response' }}<textarea name="message" required minlength="10" maxlength="5000" rows="4" class="mt-2 block w-full rounded-md border border-[var(--border-input)] bg-[var(--bg-surface)] p-3">{{ old('message') }}</textarea></label>
        <label class="block text-sm font-semibold">Evidence (optional)<input name="evidence[]" type="file" multiple accept="image/jpeg,image/png,application/pdf" class="mt-2 block w-full rounded-md border border-[var(--border-input)] p-2"><span class="mt-2 block text-xs font-normal">Up to five JPG, PNG or PDF files, 5 MB each.</span></label>
        <button type="submit" class="min-h-11 rounded-md bg-[var(--btn-accent)] px-5 font-semibold text-[var(--btn-accent-contrast)]">Record update</button>
    </form>
    
@elseif($role === 'seller')<p class="text-sm text-[var(--text-muted)]">Admin will notify you if a response is required.</p>
@endif
</div>
@endsection
