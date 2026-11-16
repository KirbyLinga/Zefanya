@extends($layout)
@section('title', 'Warranty / Consumer Claims')
@section('content')
<div class="mx-auto max-w-6xl p-4 sm:p-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Warranty / Consumer Claims</h1>
    <p class="mt-2 text-sm text-[var(--text-muted)]">Separate from ordinary seven-day returns. Cases receive manual review; decisions here do not move money, stock or parcels.</p>
    <div class="mt-6 overflow-x-auto rounded-lg border border-[var(--border)] bg-[var(--bg-surface)]">
        <table class="w-full text-left text-sm text-[var(--text-ink)]"><thead><tr><th class="p-3">Case</th><th class="p-3">Product</th><th class="p-3">Status</th><th class="p-3">Updated</th><th class="p-3">Action</th></tr></thead>
        <tbody>
@forelse($claims as $claim)<tr class="border-t border-[var(--border)]"><td class="p-3">#{{ $claim->id }}</td><td class="p-3">{{ $claim->product_name }}</td><td class="p-3">{{ str_replace('_', ' ', $claim->status) }}</td><td class="p-3">{{ $claim->updated_at->format('M j, Y') }}</td><td class="p-3"><a class="font-semibold text-[var(--link)] underline" href="{{ route($role.'.consumer-claims.show', $claim->id) }}">Review case</a></td></tr>
@empty<tr><td colspan="5" class="p-6">No claims to show. Buyers can start a claim from an order with a recorded delivery.</td></tr>
@endforelse</tbody></table>
    </div>
    <div class="mt-4">{{ $claims->links() }}</div>
</div>
@endsection
