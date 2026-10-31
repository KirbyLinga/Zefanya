@extends('Admin.Layouts.app')

@section('title', 'Vouchers')

@use('App\Enums\VoucherType')
@use('App\Models\Seller\StoreSale')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-serif text-3xl text-neutral-950">Vouchers</h1>
            <p class="mt-2 text-sm text-neutral-600">Platform vouchers such as PLAT100 discount merchandise. Shipping vouchers such as FREESHIP discount shipping fees. The marketplace funds both. Times are Philippine time.</p>
        </div>
        <a href="{{ route('admin.vouchers.create') }}" class="rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900">New voucher</a>
    </div>

    @if(session('success'))
        <div role="status" class="rounded-md border border-tertiary-300 bg-tertiary-50 p-4 text-sm text-tertiary-800">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div role="alert" class="rounded-md border border-error p-4 text-sm text-error">{{ $errors->first() }}</div>
    @endif

    @if($vouchers->isEmpty())
        <div class="flex flex-col items-center justify-center text-center py-16 px-4 border border-neutral-200 rounded-lg bg-white">
            <p class="font-sans text-sm font-semibold text-neutral-950 mb-1">No vouchers yet</p>
            <p class="font-sans text-[12.5px] text-neutral-600 max-w-[360px]">Platform and shipping vouchers you create will show up here.</p>
        </div>
    @else
        <div class="overflow-x-auto border border-neutral-200 rounded-lg bg-white">
            <table class="w-full border-collapse text-[13.5px] text-neutral-950">
                <thead>
                    <tr class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-neutral-500">
                        <th class="px-3 py-2 border-b border-neutral-200">Code</th>
                        <th class="px-3 py-2 border-b border-neutral-200">Kind</th>
                        <th class="px-3 py-2 border-b border-neutral-200">Discount</th>
                        <th class="px-3 py-2 border-b border-neutral-200">Used</th>
                        <th class="px-3 py-2 border-b border-neutral-200">Runs</th>
                        <th class="px-3 py-2 border-b border-neutral-200">Status</th>
                        <th class="px-3 py-2 border-b border-neutral-200"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($vouchers as $voucher)
                        <tr>
                            <td class="px-3 py-3 border-b border-neutral-200">
                                <p class="font-mono font-semibold">{{ $voucher->code }}</p>
                                <p class="text-[12px] text-neutral-500">{{ $voucher->is_claimable ? 'Claimable' : 'Code only' }}</p>
                            </td>
                            <td class="px-3 py-3 border-b border-neutral-200">{{ $voucher->effectiveScope()->label() }}</td>
                            <td class="px-3 py-3 border-b border-neutral-200">
                                @if($voucher->type === VoucherType::Percent)
                                    <p>{{ $voucher->value }}% off</p>
                                    @if($voucher->max_discount !== null)
                                        <p class="text-[12px] text-neutral-500">Up to ₱{{ number_format($voucher->max_discount / 100, 2) }}</p>
                                    @endif
                                @else
                                    <p>₱{{ number_format($voucher->value / 100, 2) }} off</p>
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-neutral-200 whitespace-nowrap">
                                <p>{{ $voucher->times_used }} / {{ $voucher->usage_limit ?? 'No limit' }}</p>
                            </td>
                            <td class="px-3 py-3 border-b border-neutral-200 whitespace-nowrap text-[12px]">
                                <p>{{ $voucher->starts_at?->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A') }}</p>
                                <p>{{ $voucher->expires_at?->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A') }}</p>
                            </td>
                            <td class="px-3 py-3 border-b border-neutral-200">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full font-sans text-[10.5px] font-semibold tracking-[0.4px] uppercase bg-neutral-100 text-neutral-950">{{ $voucher->statusLabel() }}</span>
                            </td>
                            <td class="px-3 py-3 border-b border-neutral-200">
                                <div class="flex items-center justify-end gap-3">
                                    @unless($voucher->hasEnded())
                                        <a href="{{ route('admin.vouchers.edit', $voucher) }}" class="font-sans text-[12px] font-semibold text-primary-800">Edit</a>
                                        <form method="POST" action="{{ route('admin.vouchers.end', $voucher) }}" onsubmit="return confirm('End this voucher for good? Buyers can no longer use it, and it leaves every wallet where it was not used.')">
                                            @csrf
                                            <button type="submit" class="font-sans text-[12px] font-semibold text-primary-800">End</button>
                                        </form>
                                    @endunless
                                    <form method="POST" action="{{ route('admin.vouchers.destroy', $voucher) }}" onsubmit="return confirm('Delete this voucher?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="font-sans text-[12px] font-semibold text-error">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-5">
            {{ $vouchers->links() }}
        </div>
    @endif
</div>
@endsection
