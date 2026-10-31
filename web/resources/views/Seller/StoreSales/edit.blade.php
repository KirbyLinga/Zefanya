@extends('Layouts.seller')

@section('title', 'Edit Sale')

@use('App\Enums\StoreSaleStatus')

@section('content')
<div class="mx-auto max-w-2xl space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="font-serif text-3xl text-[var(--text-ink)]">Edit Sale</h1>
        <a href="{{ route('seller.store-sales.index') }}" class="font-sans text-[12.5px] font-semibold text-[var(--link)]">← Back to sales</a>
    </div>

    <form method="POST" action="{{ route('seller.store-sales.update', $sale) }}" class="space-y-5 rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
        @csrf
        @method('PUT')
        @include('Seller.StoreSales._form')
        <button type="submit" class="rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900">Save changes</button>
    </form>

    @if($sale->status() === StoreSaleStatus::Ongoing)
        <form method="POST" action="{{ route('seller.store-sales.end', $sale) }}" onsubmit="return confirm('End this sale now? Buyers will go back to the regular price.')">
            @csrf
            <button type="submit" class="rounded-md border border-error px-4 py-2 text-sm font-semibold text-error">End Now</button>
        </form>
    @endif
</div>
@endsection
