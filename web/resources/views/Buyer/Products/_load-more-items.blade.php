{{--
    Load-more partial: renders product cards for AJAX load-more endpoint.
    Expects: $products (Collection of Product models with card data loaded)
--}}
@foreach ($products as $product)
    @include('Components.buyer.product-card', [
        'product' => $product,
        'url' => route('buyer.products.show', $product->id),
    ])
@endforeach
