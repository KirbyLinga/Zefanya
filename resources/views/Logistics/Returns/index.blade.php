@extends('Layouts.logistics')
@section('title', 'Physical Returns')
@section('content')
<h1 class="mb-6 font-serif text-2xl text-[var(--text-ink)]">Physical Returns</h1>
<x-logistics-flash :messages="['success', 'warning', 'error']" />
@if ($errors->any())<div role="alert" class="mb-4 rounded-md border border-red-200 bg-red-50 p-3 text-xs text-red-900">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif

<div class="grid gap-4 lg:grid-cols-2">
@forelse ($returns as $record)
<article class="rounded-lg border border-[var(--border-strong)] bg-[var(--bg-surface)] p-5">
    <x-return-summary :record="$record" />
    <p class="mt-3 text-xs">Original carrier: {{ $record->originProvider?->business_name ?? 'Requires review' }}</p>
    @if ($record->exception_reason)<p class="mt-3 text-xs text-amber-800">Exception: {{ $record->exception_reason }}</p>@endif
    <a href="{{ route('logistics.returns.show', $record) }}" class="mt-4 inline-flex rounded-sm bg-primary-700 px-3 py-2 text-xs text-white">Open return</a>
</article>
@empty<p class="rounded-lg border border-[var(--border-strong)] p-6 text-sm text-[var(--text-muted)]">No physical returns in your authorized network.</p>@endforelse
</div>
<div class="mt-4">{{ $returns->links() }}</div>
@endsection
