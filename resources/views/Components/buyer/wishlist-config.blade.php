<script type="application/json" id="wishlist-config">
{!! json_encode(['authenticated' => auth('buyer')->check(), 'savedIds' => $wishlistIds->keys()->values(), 'toggleUrl' => route('buyer.wishlist.toggle', ['productId' => 'PRODUCT_ID'], absolute: false)], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}
</script>
