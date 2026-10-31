@extends('Admin.Layouts.app')

@section('title', 'Edit Voucher')

@section('content')
<div class="mx-auto max-w-2xl space-y-6">
    <div class="flex items-center justify-between">
        <h1 class="font-serif text-3xl text-neutral-950">Edit Voucher</h1>
        <a href="{{ route('admin.vouchers.index') }}" class="font-sans text-[12.5px] font-semibold text-primary-800">← Back to vouchers</a>
    </div>

    <form method="POST" action="{{ route('admin.vouchers.update', $voucher) }}" class="space-y-5 rounded-lg border border-neutral-200 bg-white p-5">
        @csrf
        @method('PUT')
        @include('Seller.Vouchers._form', ['marketplace' => true])
        <button type="submit" class="rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900">Save changes</button>
    </form>

    <form method="POST" action="{{ route('admin.vouchers.end', $voucher) }}" onsubmit="return confirm('End this voucher for good? Buyers can no longer use it, and it leaves every wallet where it was not used.')">
        @csrf
        <button type="submit" class="rounded-md border border-error px-4 py-2 text-sm font-semibold text-error">End voucher</button>
    </form>
</div>
@endsection
