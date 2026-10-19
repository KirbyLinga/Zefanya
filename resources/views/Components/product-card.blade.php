{{-- Landing cards use the same padded card as every buyer catalog. --}}
@include('Components.buyer.product-card', [
    'product' => $product,
    'url' => route('buyer.products.show', $product->id),
    'quickView' => true,
])
