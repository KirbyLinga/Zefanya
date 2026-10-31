@extends('Layouts.seller')
@section('title', 'Sub-categories')
@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div><h1 class="font-serif text-3xl text-[var(--text-ink)]">Sub-categories</h1><p class="mt-2 text-sm text-[var(--text-muted)]">Sort your products under your main category{{ $seller->lineOfBusiness ? ', '.$seller->lineOfBusiness->label : '' }}. A product can have up to three.</p></div>
        <a href="{{ route('buyer.stores.show', $seller) }}" class="rounded-md border border-[var(--border-input)] px-4 py-2 text-sm text-[var(--text-ink)]">View store</a>
    </div>
    @if(session('success'))<div role="status" class="rounded-md border border-tertiary-300 bg-tertiary-50 p-4 text-sm text-tertiary-800">{{ session('success') }}</div>@endif
    @if($errors->any())
        <div role="alert" class="rounded-md border border-error bg-[var(--bg-surface)] p-4 text-sm text-error"><p class="font-semibold">Please review the following:</p><ul class="mt-2 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif
    @php($field = 'rounded-md border border-[var(--border-input)] bg-[var(--bg-surface)] px-3 py-2 text-sm text-[var(--text-ink)]')
    <section class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
        <h2 class="mb-4 font-serif text-xl text-[var(--text-ink)]">Create category</h2>
        <form method="POST" action="{{ route('seller.store-categories.store') }}" class="flex flex-col gap-4 md:flex-row md:items-end">
            @csrf
            <label class="flex flex-1 flex-col gap-2 text-sm text-[var(--text-ink)]">Name<input name="name" value="{{ is_string(old('name')) ? old('name') : '' }}" required maxlength="50" class="{{ $field }}"></label>
            <label class="flex flex-1 flex-col gap-2 text-sm text-[var(--text-ink)]">Parent category<select name="parent_id" class="{{ $field }}"><option value="">Top-level category</option>@foreach($categories->whereNull('parent_id') as $parent)<option value="{{ $parent->id }}" @selected(old('parent_id') == $parent->id)>{{ $parent->name }}</option>@endforeach</select></label>
            <button class="cursor-pointer rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900">Create category</button>
        </form>
    </section>
    <section class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
        <h2 class="mb-4 font-serif text-xl text-[var(--text-ink)]">Your categories</h2>
        <div class="space-y-3">
            @forelse($categories as $category)
                <details class="rounded-md border border-[var(--border-input)] p-3">
                    <summary class="cursor-pointer text-sm text-[var(--text-ink)]">{{ $category->parent ? $category->parent->name.' / ' : '' }}{{ $category->name }} <span class="text-[var(--text-muted)]">({{ $category->products_count }} products)</span></summary>
                    <div class="mt-3 flex flex-wrap items-center gap-3 text-sm text-[var(--text-ink)]">
                        <span>{{ $category->is_visible ? 'Shown' : 'Hidden' }}{{ $category->parent && ! $category->parent->is_visible ? ' (parent hidden)' : '' }}</span>
                        @foreach(['up' => 'Move up', 'down' => 'Move down'] as $direction => $label)
                            <form method="POST" action="{{ route('seller.store-categories.move', $category) }}">@csrf<input type="hidden" name="direction" value="{{ $direction }}"><button class="rounded-md border border-[var(--border-input)] px-3 py-2">{{ $label }}</button></form>
                        @endforeach
                        <form method="POST" action="{{ route('seller.store-categories.visibility', $category) }}">@csrf @method('PATCH')<input type="hidden" name="is_visible" value="{{ $category->is_visible ? '0' : '1' }}"><button class="rounded-md border border-[var(--border-input)] px-3 py-2">{{ $category->is_visible ? 'Hide' : 'Show' }}</button></form>
                        <a class="text-error underline" href="{{ route('seller.store-categories.delete-confirmation', $category) }}">Delete category</a>
                    </div>
                    <form method="POST" action="{{ route('seller.store-categories.update', $category) }}" class="mt-4 flex flex-col gap-3 md:flex-row">
                        @csrf @method('PATCH')
                        <label class="flex flex-1 flex-col gap-2 text-sm text-[var(--text-ink)]">Name<input name="name" value="{{ $category->name }}" required maxlength="50" class="{{ $field }}"></label>
                        <label class="flex flex-1 flex-col gap-2 text-sm text-[var(--text-ink)]">Parent category<select name="parent_id" class="{{ $field }}"><option value="">Top-level category</option>@foreach($categories->whereNull('parent_id')->where('id', '!=', $category->id) as $parent)<option value="{{ $parent->id }}" @selected($category->parent_id == $parent->id)>{{ $parent->name }}</option>@endforeach</select></label>
                        <button class="self-end rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white">Save category</button>
                    </form>
                </details>
            @empty<p class="text-sm text-[var(--text-muted)]">No store categories yet.</p>@endforelse
        </div>
    </section>
    <section class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
        <h2 class="mb-4 font-serif text-xl text-[var(--text-ink)]">Product categories</h2>
        <form method="GET" action="{{ route('seller.store-categories.index') }}" class="flex flex-wrap items-end gap-3">
            <label class="flex flex-1 flex-col gap-2 text-sm text-[var(--text-ink)]">Product<select name="product" required class="{{ $field }}"><option value="">Choose a product</option>@foreach($products as $product)<option value="{{ $product->id }}" @selected($selectedProduct?->id === $product->id)>{{ $product->name }}</option>@endforeach</select></label>
            <button class="rounded-md border border-[var(--border-input)] px-4 py-2 text-sm text-[var(--text-ink)]">Choose product</button>
        </form>
        @if($selectedProduct)
            @if($selectedProduct->storeCategories->isEmpty())<p class="mt-4 text-sm text-[var(--text-muted)]">{{ $selectedProduct->name }} is in Uncategorized.</p>@endif
            <form method="POST" action="{{ route('seller.store-categories.assign', $selectedProduct) }}" class="mt-5 space-y-4">
                @csrf @method('PATCH')
                <fieldset class="grid gap-3 sm:grid-cols-2"><legend class="mb-3 text-sm font-semibold text-[var(--text-ink)]">Choose up to three store categories for {{ $selectedProduct->name }}</legend>
                    @foreach($categories as $category)
                        <label class="flex items-center gap-3 text-sm text-[var(--text-ink)]"><input type="checkbox" name="store_category_ids[]" value="{{ $category->id }}" @checked($selectedProduct->storeCategories->contains('id', $category->id)) class="accent-primary-800">{{ $category->parent ? $category->parent->name.' / ' : '' }}{{ $category->name }}</label>
                    @endforeach
                </fieldset>
                <button class="rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white">Save product categories</button>
            </form>
        @endif
    </section>
</div>
@endsection
