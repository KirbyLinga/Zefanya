@extends('Layouts.seller')

@section('title', 'Orders')

@use('App\Enums\OrderStatus')

@push('scripts')
<script>
    function confirmAccept(btn) {
        if (confirm('Accept this order? You can pack it in the next step.')) {
            btn.closest('form').submit();
        }
    }
    function confirmMarkReady(btn) {
        if (confirm('Mark this order as packed and ready for pickup? A courier will be assigned at pickup time.')) {
            btn.closest('form').submit();
        }
    }
</script>
@endpush

@section('content')
<div class="mx-auto w-full max-w-[1440px]">
<div class="mb-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Orders</h1>
    <p class="mt-1 font-sans text-[13px] text-[var(--text-muted)]">First come, first served: oldest orders first, and orders you still need to prepare stay on top.</p>
</div>

{{-- ── Filter tabs ── --}}
@php(
    $tabs = [
        ['key' => 'all', 'label' => 'All orders'],
        ['key' => 'to_ship', 'label' => 'To ship'],
        ['key' => 'ready', 'label' => 'Ready for pickup'],
        ['key' => 'shipped', 'label' => 'Shipped'],
        ['key' => 'delivered', 'label' => 'Delivered'],
        ['key' => 'cancelled', 'label' => 'Cancelled'],
    ]
)

<nav aria-label="Filter orders by status" class="mb-5 flex flex-wrap gap-2 rounded-xl border border-[var(--border-strong)] bg-[var(--bg-surface)] p-2 shadow-sm">
    @foreach($tabs as $tab)
        @php($active = $bucket === $tab['key'])
        <a href="{{ route('seller.orders.index', ['bucket' => $tab['key']]) }}"
           @if($active) aria-current="page" @endif
           class="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border px-3 py-2 font-sans text-[12.5px] font-semibold no-underline transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700 sm:px-4 {{ $active ? 'border-primary-800 bg-primary-800 text-white shadow-sm' : 'border-transparent text-[var(--text-ink)] hover:border-primary-200 hover:bg-primary-50 hover:text-primary-900' }}">
            <span class="text-inherit">{{ $tab['label'] }}</span>
            <span class="inline-flex min-w-6 items-center justify-center rounded-full px-1.5 py-0.5 text-[11px] leading-4 tabular-nums {{ $active ? 'bg-white/20 text-white' : 'bg-[var(--bg-hover)] text-[var(--text-ink)]' }}">{{ $tabCounts[$tab['key']] }}</span>
        </a>
    @endforeach
</nav>
@if($sellerOrders->isEmpty())
    <div class="flex flex-col items-center justify-center text-center py-16 px-4 border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)]">
        <i data-lucide="shopping-bag" class="w-[28px] h-[28px] text-[var(--icon-soft)] mb-3"></i>
        <p class="font-sans text-sm font-semibold text-[var(--text-ink)] mb-1">No orders in this view</p>
        <p class="font-sans text-[12.5px] text-[var(--text-muted)] max-w-[320px]">
            @if($bucket === 'to_ship')
                New orders from buyers will appear here.
            @elseif($bucket === 'ready')
                Orders you've packed will show up here, waiting for a courier.
            @elseif($bucket === 'shipped')
                Orders handed to a courier will show up here.
            @elseif($bucket === 'cancelled')
                Cancelled orders will show up here.
            @else
                Delivered and returned orders will show up here.
            @endif
        </p>
    </div>
@else
    <div class="overflow-x-auto border border-[var(--border-strong)] rounded-xl bg-[var(--bg-surface)] shadow-sm">
        <table class="w-full min-w-[860px] table-fixed border-collapse text-center [&_td]:align-middle">
            <thead class="bg-[var(--bg-hover)]">
                <tr>
                    <th class="text-center font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-4 py-4 border-b border-[var(--border-strong)]">Order</th>
                    <th class="text-center font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-4 py-4 border-b border-[var(--border-strong)]">Buyer</th>
                    <th class="text-center font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-4 py-4 border-b border-[var(--border-strong)]">Items</th>
                    <th class="text-center font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-4 py-4 border-b border-[var(--border-strong)]">Total</th>
                    <th class="text-center font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-4 py-4 border-b border-[var(--border-strong)]">Status</th>
                    <th class="text-center font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-4 py-4 border-b border-[var(--border-strong)]">Action</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sellerOrders as $sellerOrder)
                    @php($order = $sellerOrder->order ?? null)
                    <tr class="hover:bg-[var(--bg-hover)] transition-colors">
                        <td class="px-4 py-4 text-[13.5px] text-[var(--text-ink)] border-b border-[var(--border-strong)] whitespace-nowrap">
                            <a href="{{ route('seller.orders.show', $sellerOrder) }}" class="font-mono font-semibold text-[var(--text-ink)] no-underline hover:underline">#{{ $sellerOrder->id }}</a>
                            <span class="block font-sans text-[12px] text-[var(--text-muted)]">{{ $sellerOrder->created_at->timezone(\App\Models\Seller\StoreSale::TIMEZONE)->format('M d, Y') }}</span>
                        </td>
                        <td class="px-4 py-4 text-[13.5px] text-[var(--text-ink)] border-b border-[var(--border-strong)]">{{ $order?->buyer?->fullName() ?? '—' }}</td>
                        <td class="px-4 py-4 text-[13.5px] text-[var(--text-ink)] border-b border-[var(--border-strong)]">
                            <span class="mx-auto block max-w-[320px] truncate text-[var(--text-ink)]" title="{{ $sellerOrder->itemsSummary() }}">{{ $sellerOrder->itemsSummary() }}</span>
                            <span class="block font-sans text-[12px] text-[var(--text-muted)]">Qty {{ $sellerOrder->orderItems->sum('quantity') }}</span>
                            @if ($sellerOrder->buyer_note)
                                <details data-buyer-note class="group mx-auto mt-2 max-w-[320px] text-left font-sans text-[12px]">
                                    <summary class="flex cursor-pointer items-center justify-center gap-1 rounded-md px-2 py-1 font-semibold text-[var(--link)] transition-colors hover:bg-[var(--bg-hover)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">
                                        <i data-lucide="message-square-text" class="size-3.5 shrink-0" aria-hidden="true"></i>
                                        <span class="text-inherit group-open:hidden">View buyer note</span>
                                        <span class="hidden text-inherit group-open:inline">Hide buyer note</span>
                                    </summary>
                                    <p class="mt-2 whitespace-pre-wrap rounded-lg border border-[var(--border-strong)] bg-[var(--bg-hover)] p-3 leading-relaxed text-[var(--text-ink)] [overflow-wrap:anywhere]">{{ $sellerOrder->buyer_note }}</p>
                                </details>
                            @endif
                        </td>
                        <td class="px-4 py-4 whitespace-nowrap text-[13.5px] tabular-nums font-semibold text-[var(--text-ink)] border-b border-[var(--border-strong)] text-center">
                            {{ $sellerOrder->formattedSubtotal() }}
                        </td>
                        <td class="px-4 py-4 border-b border-[var(--border-strong)]">
                            <x-seller.order-status-pill :status="$sellerOrder->status" />
                        </td>
                        <td class="px-4 py-4 border-b border-[var(--border-strong)] text-center">
                            @if($sellerOrder->status === OrderStatus::Placed)
                                <form method="POST" action="{{ route('seller.orders.accept', $sellerOrder) }}">
                                    @csrf
                                    <button type="button"
                                        class="inline-flex min-h-9 items-center justify-center px-3 rounded-lg font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-primary-700 hover:bg-primary-800"
                                        onclick="confirmAccept(this)">
                                        Accept order
                                    </button>
                                </form>
                            @elseif(in_array($sellerOrder->status, [OrderStatus::Confirmed, OrderStatus::Preparing]))
                                <form method="POST" action="{{ route($sellerOrder->status === OrderStatus::Confirmed ? 'seller.orders.pack' : 'seller.orders.markReady', $sellerOrder) }}">
                                    @csrf
                                    <button type="button"
                                        class="inline-flex min-h-9 items-center justify-center px-3 rounded-lg font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-primary-700 hover:bg-primary-800"
                                        onclick="{{ $sellerOrder->status === OrderStatus::Confirmed ? 'this.form.submit()' : 'confirmMarkReady(this)' }}">
                                        {{ $sellerOrder->status === OrderStatus::Confirmed ? 'Pack order' : 'Mark ready' }}
                                    </button>
                                </form>
                            @else
                                <a href="{{ route('seller.orders.show', $sellerOrder) }}" class="inline-flex min-h-9 items-center justify-center rounded-lg border border-[var(--border-strong)] px-4 font-sans text-[12px] font-semibold text-[var(--link)] transition-colors duration-200 hover:border-primary-700 hover:bg-primary-700 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700">View</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-5">
        {{ $sellerOrders->links() }}
    </div>
@endif
</div>
@endsection
