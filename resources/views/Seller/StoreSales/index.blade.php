@extends('Layouts.seller')

@section('title', 'Store Sales')

@use('App\Enums\StoreSaleStatus')
@use('App\Models\Seller\StoreSale')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="font-serif text-3xl text-[var(--text-ink)]">Store Sales</h1>
            <p class="mt-2 text-sm text-[var(--text-muted)]">Run timed discounts on a product or one of its variants. A product can only have one sale at a time. Times are Philippine time.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('seller.promotions.index') }}" class="rounded-md border border-[var(--border-strong)] px-4 py-2 text-sm font-semibold text-[var(--text-ink)] hover:bg-[var(--bg-surface)]">Bundle and Buy X Get Y</a>
            <a href="{{ route('seller.store-sales.create') }}" class="rounded-md bg-primary-800 px-4 py-2 text-sm font-semibold text-white hover:bg-primary-900">New sale</a>
        </div>
    </div>

    @if(session('success'))
        <div role="status" class="rounded-md border border-tertiary-300 bg-tertiary-50 p-4 text-sm text-tertiary-800">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div role="alert" class="rounded-md border border-error p-4 text-sm text-error">{{ $errors->first() }}</div>
    @endif

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
</div>
@endsection
