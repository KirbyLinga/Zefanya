@extends('Layouts.seller')
@section('title', 'Delete Store Category')
@section('content')
<section class="mx-auto max-w-2xl space-y-5 rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-6 text-[var(--text-ink)]">
    <h1 class="font-serif text-3xl">Delete {{ $category->name }}?</h1>
    @if($errors->any())<div role="alert" class="rounded-md border border-error p-4 text-sm text-error"><ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <p>{{ $affected_count }} {{ Str::plural('product', $affected_count) }} affected. {{ $uncategorized_count }} {{ Str::plural('product', $uncategorized_count) }} will move to Uncategorized.</p>
    <p>Products will stay in your store. Their platform categories, stock and order history will stay unchanged. Memberships in other store categories will be kept.</p>
    @if($children->isNotEmpty())<div><p class="font-semibold">These subcategories will also be deleted:</p><ul class="mt-2 list-disc pl-5">@foreach($children as $child)<li>{{ $child->name }}</li>@endforeach</ul></div>@endif
    <div class="flex flex-wrap gap-3">
        <a href="{{ route('seller.store-categories.index') }}" class="rounded-md border border-[var(--border-input)] px-4 py-2">Cancel</a>
        <form method="POST" action="{{ route('seller.store-categories.destroy', $category) }}">@csrf @method('DELETE')<input type="hidden" name="confirmed" value="1"><input type="hidden" name="revision" value="{{ $revision }}"><button class="rounded-md bg-primary-800 px-4 py-2 font-semibold text-white">Confirm deletion</button></form>
    </div>
</section>
@endsection
