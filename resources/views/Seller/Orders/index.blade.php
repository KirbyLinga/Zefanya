@extends('Layouts.seller')

@section('title', 'Orders')

@use('App\Enums\OrderStatus')

@push('scripts')
<script>
    function confirmAccept(btn) {
        if (confirm('Accept this order? You will start preparing it for shipment.')) {
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
<div class="flex items-center justify-between mb-6">
    <h1 class="font-serif text-2xl text-[var(--text-ink)]">Orders</h1>
</div>

{{-- ── Filter tabs ── --}}
@php(
    $tabs = [
        ['key' => 'all', 'label' => 'All orders'],
        ['key' => 'to_ship', 'label' => 'To ship'],
        ['key' => 'ready', 'label' => 'Ready for pickup'],
        ['key' => 'shipped', 'label' => 'Shipped'],
        ['key' => 'delivered', 'label' => 'Delivered'],
    ]
)

<div class="flex gap-2 mb-5 border-b border-[var(--border-strong)]">
    @foreach($tabs as $tab)
        @php($active = $bucket === $tab['key'])
        <a href="{{ route('seller.orders.index', ['bucket' => $tab['key']]) }}"
           class="px-4 py-2.5 -mb-px font-sans text-[12.5px] font-semibold tracking-[0.5px] border-b-2 transition-colors
                  {{ $active ? 'border-[var(--link)] text-[var(--link)]' : 'border-transparent text-[var(--text-muted)] hover:text-[var(--text-ink)]' }}">
            {{ $tab['label'] }}
        </a>
    @endforeach
</div>

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
            @else
                Delivered and returned orders will show up here.
            @endif
        </p>
    </div>
@else
    <div class="overflow-x-auto border border-[var(--border-strong)] rounded-lg bg-[var(--bg-surface)]">
        <table class="w-full border-collapse">
            <thead>
                <tr>
                    <th class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-3 py-2 border-b border-[var(--border-strong)]">Order</th>
                    <th class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-3 py-2 border-b border-[var(--border-strong)]">Buyer</th>
                    <th class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-3 py-2 border-b border-[var(--border-strong)]">Items</th>
                    <th class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-3 py-2 border-b border-[var(--border-strong)]">Status</th>
                    <th class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-3 py-2 border-b border-[var(--border-strong)]">Tracking</th>
                    <th class="text-right font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-3 py-2 border-b border-[var(--border-strong)]">Total</th>
                    <th class="text-left font-sans text-[11px] font-semibold tracking-[0.6px] uppercase text-[var(--text-muted)] px-3 py-2 border-b border-[var(--border-strong)]">Date</th>
                    <th class="px-3 py-2"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($sellerOrders as $sellerOrder)
                    @php($order = $sellerOrder->order ?? null)
                    <tr class="hover:bg-[var(--bg-hover)] transition-colors">
                        <td class="px-3 py-3 text-[13.5px] font-mono text-[var(--text-ink)] border-b border-[var(--border-strong)]">#{{ $sellerOrder->id }}</td>
                        <td class="px-3 py-3 text-[13.5px] text-[var(--text-ink)] border-b border-[var(--border-strong)]">{{ $order?->buyer?->fullName() ?? '—' }}</td>
                        <td class="px-3 py-3 text-[13.5px] text-[var(--text-ink)] border-b border-[var(--border-strong)]">{{ $sellerOrder->orderItems->sum('quantity') }}</td>
                        <td class="px-3 py-3 border-b border-[var(--border-strong)]">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full font-sans text-[10.5px] font-semibold tracking-[0.4px] uppercase
                                @if(in_array($sellerOrder->status, [OrderStatus::Placed, OrderStatus::Confirmed, OrderStatus::Preparing]))
                                    bg-[#fdf1de] text-[#9a6a1f]
                                @elseif(in_array($sellerOrder->status, [OrderStatus::ReadyForPickup, OrderStatus::AssignedToRider, OrderStatus::PickedUp, OrderStatus::AtSortingCenter, OrderStatus::Sorted, OrderStatus::OutForDelivery]))
                                    bg-[#e0f2fe] text-[#0369a1]
                                @else
                                    bg-[#dcfce7] text-[#15803d]
                                @endif
                                ">
                                {{ $sellerOrder->status->label() }}
                            </span>
                        </td>
                        <td class="px-3 py-3 text-[13.5px] text-[var(--text-ink)] border-b border-[var(--border-strong)]">
                            {{ $sellerOrder->tracking_number ?: '—' }}
                        </td>
                        <td class="px-3 py-3 text-[13.5px] font-semibold text-[var(--text-ink)] border-b border-[var(--border-strong)] text-right">
                            {{ $sellerOrder->formattedSubtotal() }}
                        </td>
                        <td class="px-3 py-3 text-[13.5px] text-[var(--text-ink)] border-b border-[var(--border-strong)]">{{ $sellerOrder->created_at->format('M d, Y') }}</td>
                        <td class="px-3 py-3 border-b border-[var(--border-strong)]">
                            @if($sellerOrder->status === OrderStatus::Placed)
                                <form method="POST" action="{{ route('seller.orders.accept', $sellerOrder) }}">
                                    @csrf
                                    <button type="button"
                                        class="inline-flex items-center justify-center h-7 px-3 rounded-sm font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-primary-700 hover:bg-primary-800"
                                        onclick="confirmAccept(this)">
                                        Accept order
                                    </button>
                                </form>
                            @elseif(in_array($sellerOrder->status, [OrderStatus::Confirmed, OrderStatus::Preparing]))
                                <form method="POST" action="{{ route('seller.orders.markReady', $sellerOrder) }}">
                                    @csrf
                                    <button type="button"
                                        class="inline-flex items-center justify-center h-7 px-3 rounded-sm font-sans font-semibold text-[11.5px] tracking-[0.5px] uppercase text-white bg-primary-700 hover:bg-primary-800"
                                        onclick="confirmMarkReady(this)">
                                        Mark ready
                                    </button>
                                </form>
                            @else
                                <a href="{{ route('seller.orders.show', $sellerOrder) }}" class="font-sans text-[12px] font-semibold text-[var(--link)]">View</a>
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
@endsection
