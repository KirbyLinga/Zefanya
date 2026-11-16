@extends('Buyer.Layouts.app')
@section('title', 'My Vouchers')
@section('content')
<div class="bg-cream">
    <div class="mx-auto max-w-3xl px-4 py-8 sm:px-8">
        <div class="mb-8">
            <h1 class="font-serif text-[32px] font-semibold text-buyer-ink sm:text-[40px]">My Vouchers</h1>
            <p class="mt-2 text-[14px] text-buyer-ink-soft">Vouchers you claimed. Apply them in your cart when you shop at that store.</p>
        </div>

        @if ($claims->isEmpty())
            <div class="rounded-2xl border border-buyer-line bg-white p-8 text-center">
                <p class="text-sm font-semibold text-buyer-ink">No vouchers yet</p>
                <p class="mt-1 text-[12.5px] text-buyer-ink-soft">Vouchers you claim from a store or product page will show up here.</p>
            </div>
        @else
            <ul class="flex flex-col gap-3">
                @foreach ($claims as $claim)
                    @php($voucher = $claim->voucher)
                    @php($status = $voucher->statusLabel())
                    @php($used = $voucher->usesByBuyer((int) $claim->buyer_id) > 0)
                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-buyer-line bg-white px-5 py-4">
                        <div class="min-w-0">
                            <p class="select-all font-mono text-sm font-semibold text-buyer-ink">{{ $voucher->code }}</p>
                            <p class="text-[12px] text-buyer-ink-soft">{{ $voucher->offerSummary() }}</p>
                            @if ($voucher->seller)
                                <a href="{{ route('buyer.stores.show', ['seller' => $voucher->seller]) }}" class="text-[12px] font-semibold text-primary-800 hover:underline">{{ $voucher->seller->business_name }}</a>
                            @endif
                        </div>
                        <span class="shrink-0 rounded-full bg-secondary-100 px-3 py-1 text-[11px] font-semibold text-buyer-ink">{{ $used ? 'Used' : ($status === 'Active' ? 'Ready to use' : $status) }}</span>
                    </li>
                @endforeach
            </ul>

            <div class="mt-6">
                {{ $claims->links() }}
            </div>
        @endif
    </div>
</div>
@endsection
