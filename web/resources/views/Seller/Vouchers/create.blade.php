@extends('Layouts.seller')

@section('title', 'New Voucher')

@section('content')
<div class="mx-auto max-w-2xl space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="font-serif text-3xl text-[var(--text-ink)]">New Voucher</h1>
        <a href="{{ route('seller.vouchers.index') }}" class="font-sans text-[12.5px] font-semibold text-[var(--link)]">← Back to vouchers</a>
    </div>

    <form method="POST" action="{{ route('seller.vouchers.store') }}" class="space-y-5 rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-5">
        @csrf
        @include('Seller.Vouchers._form', ['voucher' => null])
        <button type="submit" class="rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900">Create voucher</button>
    </form>
</div>
@endsection
