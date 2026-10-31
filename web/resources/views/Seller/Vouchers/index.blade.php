@extends('Layouts.seller')

@section('title', 'Vouchers')

@use('App\Enums\VoucherType')
@use('App\Models\Seller\StoreSale')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-serif text-3xl text-[var(--text-ink)]">Vouchers</h1>
            <p class="mt-2 text-sm text-[var(--text-muted)]">Shop vouchers apply to your products only, and you fund the discount. Times are Philippine time.</p>
        </div>
        <a href="{{ route('seller.vouchers.create') }}" class="rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900">New voucher</a>
    </div>

    @if(session('success'))
        <div role="status" class="rounded-md border border-tertiary-300 bg-tertiary-50 p-4 text-sm text-tertiary-800">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div role="alert" class="rounded-md border border-error p-4 text-sm text-error">{{ $errors->first() }}</div>
    @endif

    @if($vouchers->isEmpty())
        <div class="flex flex-col items-center justify-center text-center py-16 px-4 border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)]">
            <i data-lucide="tag" class="w-[28px] h-[28px] text-[var(--icon-soft)] mb-3"></i>
            <p class="font-sans text-sm font-semibold text-[var(--text-ink)] mb-1">No vouchers yet</p>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] max-w-[320px]">Vouchers you create will show up here.</p>
        </div>
    @else
        <div class="overflow-x-auto border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)]">
            <table class="w-full border-collapse text-[13.5px] text-[var(--text-ink)]">
                <thead>
                    <tr class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)]">
                        <th class="px-3 py-2 border-b border-[var(--border-strong)]">Code</th>
                        <th class="px-3 py-2 border-b border-[var(--border-strong)]">Discount</th>
                        <th class="px-3 py-2 border-b border-[var(--border-strong)]">Used</th>
                        <th class="px-3 py-2 border-b border-[var(--border-strong)]">Applies to</th>
                        <th class="px-3 py-2 border-b border-[var(--border-strong)]">Runs</th>
                        <th class="px-3 py-2 border-b border-[var(--border-strong)]">Status</th>
                        <th class="px-3 py-2 border-b border-[var(--border-strong)]"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($vouchers as $voucher)
                        <tr>
                            <td class="px-3 py-3 border-b border-[var(--border-strong)]">
                                <p class="font-mono font-semibold">{{ $voucher->code }}</p>
                                <p class="text-[12px] text-[var(--text-muted)]">{{ $voucher->is_claimable ? 'Claimable' : 'Code only' }}</p>
                            </td>
                            <td class="px-3 py-3 border-b border-[var(--border-strong)]">
                                @if($voucher->type === VoucherType::Percent)
                                    <p>{{ $voucher->value }}% off</p>
                                    @if($voucher->max_discount !== null)
                                        <p class="text-[12px] text-[var(--text-muted)]">Up to ₱{{ number_format($voucher->max_discount / 100, 2) }}</p>
                                    @endif
                                @else
                                    <p>₱{{ number_format($voucher->value / 100, 2) }} off</p>
                                @endif
                                @if($voucher->min_spend)
                                    <p class="text-[12px] text-[var(--text-muted)]">Min. spend ₱{{ number_format($voucher->min_spend / 100, 2) }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-[var(--border-strong)] whitespace-nowrap">
                                <p>{{ $voucher->times_used }} / {{ $voucher->usage_limit ?? 'No limit' }}</p>
                                @if($voucher->per_buyer_limit !== null)
                                    <p class="text-[12px] text-[var(--text-muted)]">{{ $voucher->per_buyer_limit }} per buyer</p>
                                @endif
                            </td>
                            <td class="px-3 py-3 border-b border-[var(--border-strong)]">
                                {{ $voucher->applies_to_all_products ? 'All products' : trans_choice('{1} :count product|[2,*] :count products', $voucher->products_count, ['count' => $voucher->products_count]) }}
                            </td>
                            <td class="px-3 py-3 border-b border-[var(--border-strong)] whitespace-nowrap text-[12px]">
                                <p>{{ $voucher->starts_at?->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A') }}</p>
                                <p>{{ $voucher->expires_at?->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A') }}</p>
                            </td>
                            <td class="px-3 py-3 border-b border-[var(--border-strong)]">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full font-sans text-[10.5px] font-semibold tracking-[0.4px] uppercase bg-[var(--bg-chip)] text-[var(--text-ink)]">{{ $voucher->statusLabel() }}</span>
                            </td>
                            <td class="px-3 py-3 border-b border-[var(--border-strong)]">
                                <div class="flex items-center justify-end gap-3">
                                    @unless($voucher->hasEnded())
                                        <a href="{{ route('seller.vouchers.edit', $voucher) }}" class="font-sans text-[12px] font-semibold text-[var(--link)]">Edit</a>
                                        <form method="POST" action="{{ route('seller.vouchers.end', $voucher) }}" onsubmit="return confirm('End this voucher for good? Buyers can no longer use it, and it leaves every wallet where it was not used.')">
                                            @csrf
                                            <button type="submit" class="font-sans text-[12px] font-semibold text-[var(--link)]">End</button>
                                        </form>
                                    @endunless
                                    <form method="POST" action="{{ route('seller.vouchers.destroy', $voucher) }}" onsubmit="return confirm('Delete this voucher?')">
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
