@if ($conversation->product)
    @php
        $chatProduct = $conversation->product;
        $chatImage = $chatProduct->catalogImageUrl();
    @endphp
    <a href="{{ route('buyer.products.show', $chatProduct) }}" data-chat-product="{{ $chatProduct->id }}" class="mb-4 flex items-center gap-3 rounded-lg border border-neutral-200 bg-white p-3 text-neutral-900">
        @if ($chatImage)
            <img src="{{ $chatImage }}" alt="{{ $chatProduct->name }}" class="h-16 w-16 shrink-0 rounded-md object-cover">
        @else
            <span aria-hidden="true" class="flex h-16 w-16 shrink-0 items-center justify-center rounded-md bg-neutral-100"><i data-lucide="package" width="24" height="24"></i></span>
        @endif
        <span class="min-w-0">
            <span class="block text-xs text-neutral-500">Discussing this product</span>
            <span class="block truncate text-sm font-semibold">{{ $chatProduct->name }}</span>
            <span class="block text-sm">{{ $chatProduct->buyerPrice()->formatted() }}</span>
        </span>
    </a>
@endif
