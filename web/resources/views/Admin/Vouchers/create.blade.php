@extends('Admin.Layouts.app')

@section('title', 'New Voucher')

@section('content')
<div class="mx-auto max-w-2xl space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="font-serif text-3xl text-neutral-950">New Voucher</h1>
        <a href="{{ route('admin.vouchers.index') }}" class="font-sans text-[12.5px] font-semibold text-primary-800">← Back to vouchers</a>
    </div>

    <form method="POST" action="{{ route('admin.vouchers.store') }}" class="space-y-5 rounded-lg border border-neutral-200 bg-white p-5">
        @csrf
        @include('Seller.Vouchers._form', ['voucher' => null, 'marketplace' => true])
        <button type="submit" class="rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900">Create voucher</button>
    </form>
</div>
@endsection
