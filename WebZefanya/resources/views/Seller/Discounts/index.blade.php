@extends('Layouts.seller')

@section('title', 'Discounts & Vouchers')

@use('App\Enums\StoreSaleStatus')
@use('App\Enums\VoucherType')
@use('App\Models\Seller\StoreSale')

@section('content')
<div class="space-y-10">
    <div>
        <h1 class="font-serif text-3xl text-[var(--text-ink)]">Discounts &amp; Vouchers</h1>
        <p class="mt-2 text-sm text-[var(--text-muted)]">Run timed sales on your products and create shop vouchers in one place. Times are Philippine time.</p>
    </div>

    <noscript><p class="rounded-lg border border-[var(--border-input)] bg-[var(--bg-surface)] p-3 text-sm text-[var(--text-ink)]">Enable JavaScript to create a voucher in the modal, check its code, and review the discount before submitting.</p></noscript>

    <x-flash type="success" :message="session('success')" spacing="" />
    @if($errors->any() && old('_modal') !== 'voucher-create')
        <div role="alert" class="rounded-md border border-error p-4 text-sm text-error">{{ $errors->first() }}</div>
    @endif

    <section aria-labelledby="store-sales-heading" class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 id="store-sales-heading" class="font-serif text-2xl text-[var(--text-ink)]">Store Sales</h2>
            <p class="mt-2 text-sm text-[var(--text-muted)]">Run timed discounts on a product or one of its variants. A product can only have one sale at a time. Times are Philippine time.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('seller.store-sales.create') }}" class="rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900">New sale</a>
        </div>
    </div>

    @if($sales->isEmpty())
        <div class="flex flex-col items-center justify-center text-center py-16 px-4 border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)]">
            <i data-lucide="percent" class="w-[28px] h-[28px] text-[var(--icon-soft)] mb-3"></i>
            <p class="font-sans text-sm font-semibold text-[var(--text-ink)] mb-1">No sales yet</p>
            <p class="font-sans text-[12.5px] text-[var(--text-muted)] max-w-[320px]">Sales you create will show up here.</p>
        </div>
    @else
        <div class="overflow-x-auto border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)]">
            <table class="w-full border-collapse text-[13.5px] text-[var(--text-ink)]">
                <thead>
                    <tr class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)]">
                        <th class="px-3 py-2 border-b border-[var(--border-strong)]">Product</th>
                        <th class="px-3 py-2 border-b border-[var(--border-strong)]">Discount</th>
                        <th class="px-3 py-2 border-b border-[var(--border-strong)]">Starts</th>
                        <th class="px-3 py-2 border-b border-[var(--border-strong)]">Ends</th>
                        <th class="px-3 py-2 border-b border-[var(--border-strong)]">Status</th>
                        <th class="px-3 py-2 border-b border-[var(--border-strong)]"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($sales as $sale)
                        @php($status = $sale->status())
                        <tr>
                            <td class="px-3 py-3 border-b border-[var(--border-strong)]">
                                <p class="font-semibold">{{ $sale->product?->name ?? 'Product' }}</p>
                                <p class="text-[12px] text-[var(--text-muted)]">{{ $sale->variant ? $sale->variant->label() : 'Whole product' }}</p>
                            </td>
                            <td class="px-3 py-3 border-b border-[var(--border-strong)]">{{ $sale->discountLabel() }}</td>
                            <td class="px-3 py-3 border-b border-[var(--border-strong)] whitespace-nowrap">{{ $sale->starts_at->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A') }}</td>
                            <td class="px-3 py-3 border-b border-[var(--border-strong)] whitespace-nowrap">{{ $sale->ends_at->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A') }}</td>
                            <td class="px-3 py-3 border-b border-[var(--border-strong)]">
                                <span class="inline-flex items-center px-2.5 py-1 rounded-full font-sans text-[10.5px] font-semibold tracking-[0.4px] uppercase
                                    @if($status === StoreSaleStatus::Ongoing) bg-[#dcfce7] text-[#15803d]
                                    @elseif($status === StoreSaleStatus::Upcoming) bg-[#e0f2fe] text-[#0369a1]
                                    @else bg-[#f1f1f1] text-[#555]
                                    @endif">
                                    {{ $status->label() }}
                                </span>
                            </td>
                            <td class="px-3 py-3 border-b border-[var(--border-strong)]">
                                <div class="flex items-center justify-end gap-3">
                                    @if($status !== StoreSaleStatus::Ended)
                                        <a href="{{ route('seller.store-sales.edit', $sale) }}" class="font-sans text-[12px] font-semibold text-[var(--link)]">Edit</a>
                                    @endif
                                    @if($status === StoreSaleStatus::Ongoing)
                                        <form method="POST" action="{{ route('seller.store-sales.end', $sale) }}" onsubmit="return confirm('End this sale now? Buyers will go back to the regular price.')">
                                            @csrf
                                            <button type="submit" class="font-sans text-[12px] font-semibold text-[var(--link)]">End Now</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('seller.store-sales.destroy', $sale) }}" onsubmit="return confirm('Delete this sale?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="font-sans text-[12px] font-semibold text-error">Delete</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-5">
            {{ $sales->links() }}
        </div>
    @endif
    </section>

    <section aria-labelledby="vouchers-heading" class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 id="vouchers-heading" class="font-serif text-2xl text-[var(--text-ink)]">Vouchers</h2>
            <p class="mt-2 text-sm text-[var(--text-muted)]">Shop vouchers apply to your products only, and you fund the discount. Times are Philippine time.</p>
        </div>
        <a id="open-create-voucher" aria-haspopup="dialog" aria-controls="create-voucher-dialog" href="{{ route('seller.vouchers.create') }}" class="rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900">New voucher</a>
    </div>

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
    </section>
</div>
@include('Seller.Vouchers._create-modal', ['errors' => old('_modal') === 'voucher-create' ? $errors : new \Illuminate\Support\ViewErrorBag])
@endsection

@push('scripts')
@include('Seller.Vouchers._create-script')
@endpush
