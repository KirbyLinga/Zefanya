<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Enums\PaymentMethod;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\Wishlist;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductImage;
use App\Models\Catalog\ProductVariant;
use App\Models\Logistics\ParcelReturn;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

/**
 * Buyer mobile commerce API: catalog, cart, vouchers, addresses, COD
 * checkout, cancellation, notifications and wishlist. Every endpoint reuses
 * the web services, so these tests pin the API contract and its security:
 * ownership, server-side pricing, stale-state 409s and duplicate submits.
 */
class BuyerCommerceApiTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    private Seller $seller;

    private Product $product;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now(), 'street' => 'Home Street']);
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);

        $this->product = Product::factory()->create(['seller_id' => $this->seller->id, 'price' => 100.00, 'stock_quantity' => 5, 'status' => 'active', 'weight_grams' => 500]);
        $this->variant = ProductVariant::query()->where('product_id', $this->product->id)->firstOrFail();
        $this->variant->forceFill(['price' => 100.00, 'stock_quantity' => 5, 'is_available' => true, 'weight_grams' => null])->save();

        BuyerAddress::query()->create([
            'buyer_id' => $this->buyer->id, 'address_mode' => 'api',
            'province_code' => '030540000', 'province_name' => 'Pampanga',
            'municipality_code' => '030540100', 'municipality_name' => 'San Fernando',
            'barangay_code' => '0305401001', 'barangay_name' => 'First',
            'street' => 'Home Street', 'is_default' => true, 'default_marker' => 1,
        ]);
    }

    private function api(?Buyer $buyer = null): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $token = ($buyer ?? $this->buyer)->createToken('api-token', ['buyer'], now()->addHour())->plainTextToken;

        return $this->withToken($token)->withHeader('Accept', 'application/json');
    }

    private function otherBuyer(): Buyer
    {
        return Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
    }

    private function addToCart(int $quantity = 1): int
    {
        return $this->api()->postJson('/api/buyer/cart/items', ['product_id' => $this->product->id, 'quantity' => $quantity])
            ->assertCreated()->json('data.items.0.id');
    }

    /** @param array<string, mixed> $extra */
    private function quote(array $itemIds, array $extra = []): TestResponse
    {
        return $this->api()->postJson('/api/buyer/checkout/quote', ['item_ids' => $itemIds] + $this->checkoutCarrierPayload([$this->seller]) + $extra)->assertOk();
    }

    /**
     * @param  array<int>  $itemIds
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function placePayload(array $itemIds, array $override = []): array
    {
        $quote = $this->quote($itemIds);

        return array_merge([
            'item_ids' => $itemIds,
            'checkout_token' => $quote->json('data.checkout_token'),
            'price_fingerprint' => $quote->json('data.fingerprints.price'),
            'address_fingerprint' => $quote->json('data.fingerprints.address'),
            'shipping_fingerprint' => $quote->json('data.fingerprints.shipping'),
            'voucher_fingerprint' => $quote->json('data.fingerprints.voucher'),
        ] + $this->checkoutCarrierPayload([$this->seller]), $override);
    }

    // ── Catalog ────────────────────────────────────────────────────────────

    public function test_catalog_is_public_and_prices_come_from_the_server(): void
    {
        $this->getJson('/api/buyer/categories')->assertOk()->assertJsonPath('success', true);

        $this->getJson('/api/buyer/products')->assertOk()
            ->assertJsonPath('data.data.0.id', $this->product->id)
            ->assertJsonPath('data.data.0.price.min_minor', 10000)
            ->assertJsonPath('data.meta.total', 1);

        $this->getJson('/api/buyer/products/'.$this->product->id)->assertOk()
            ->assertJsonPath('data.variants.0.id', $this->variant->id)
            ->assertJsonPath('data.variants.0.price.min_minor', 10000);
    }

    public function test_catalog_image_urls_are_absolute_for_the_app(): void
    {
        ProductImage::query()->create([
            'product_id' => $this->product->id,
            'path' => 'products/'.$this->product->id.'/photo.jpg',
            'thumbnail_path' => 'thumbnails/products/'.$this->product->id.'/photo.jpg.webp',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $card = $this->getJson('/api/buyer/products')->assertOk()->json('data.data.0.image_url');
        $this->assertSame(url('/storage/thumbnails/products/'.$this->product->id.'/photo.jpg.webp'), $card);
        $this->assertMatchesRegularExpression('#^https?://#', $card);

        $detail = $this->getJson('/api/buyer/products/'.$this->product->id)->assertOk()->json('data.images.0');
        $this->assertSame(url('/storage/products/'.$this->product->id.'/photo.jpg'), $detail);
    }

    public function test_catalog_search_filters_and_hidden_products(): void
    {
        $this->getJson('/api/buyer/products?q='.urlencode('zzzz-no-such-thing'))->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->getJson('/api/buyer/products?min_price=500&max_price=100')->assertStatus(422);

        $this->product->forceFill(['status' => 'draft'])->save();
        $this->getJson('/api/buyer/products/'.$this->product->id)->assertNotFound();
    }

    // ── Cart ───────────────────────────────────────────────────────────────

    public function test_cart_requires_a_buyer_token(): void
    {
        $this->getJson('/api/buyer/cart')->assertUnauthorized();
    }

    public function test_cart_add_caps_at_stock_and_totals_are_server_side(): void
    {
        $response = $this->api()->postJson('/api/buyer/cart/items', ['product_id' => $this->product->id, 'quantity' => 9, 'unit_price_minor' => 1])
            ->assertCreated()
            ->assertJsonPath('data.quantity', 5)
            ->assertJsonPath('data.quantity_warning', 'Only 5 available.');

        $this->assertSame(50000, $response->json('data.summary.total_raw'));
        $this->assertSame(5, CartItem::query()->where('buyer_id', $this->buyer->id)->sole()->quantity);
    }

    public function test_cart_lines_belong_to_their_buyer(): void
    {
        $lineId = $this->addToCart();
        $stranger = $this->otherBuyer();

        $this->api($stranger)->patchJson("/api/buyer/cart/items/{$lineId}", ['quantity' => 2])->assertNotFound();
        $this->api($stranger)->deleteJson("/api/buyer/cart/items/{$lineId}")->assertNotFound();
        $this->api($stranger)->deleteJson('/api/buyer/cart/items', ['ids' => [$lineId]])->assertForbidden();

        $this->assertDatabaseHas('cart_items', ['id' => $lineId, 'quantity' => 1]);

        $this->api()->patchJson("/api/buyer/cart/items/{$lineId}", ['quantity' => 3])->assertOk()->assertJsonPath('data.quantity', 3);
        $this->api()->deleteJson("/api/buyer/cart/items/{$lineId}")->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_unavailable_product_cannot_be_added(): void
    {
        $this->product->forceFill(['status' => 'draft'])->save();

        $this->api()->postJson('/api/buyer/cart/items', ['product_id' => $this->product->id, 'quantity' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('product_id');
    }

    // ── Vouchers ───────────────────────────────────────────────────────────

    public function test_voucher_is_validated_statelessly_and_dropped_when_invalid(): void
    {
        $this->addToCart(2);
        $voucher = Voucher::factory()->create(['code' => 'SAVE50', 'value' => 5000]);

        $this->api()->postJson('/api/buyer/cart/vouchers', ['code' => 'NOPE'])->assertStatus(422)->assertJsonValidationErrors('code');

        $applied = $this->api()->postJson('/api/buyer/cart/vouchers', ['code' => 'save50'])->assertOk();
        $state = $applied->json('data.vouchers_state');
        $this->assertContains('SAVE50', $state);
        $this->assertSame(15000, $applied->json('data.summary.total_raw'));

        // The app sends its state back; the server re-validates it every time.
        $this->api()->getJson('/api/buyer/cart?'.http_build_query(['vouchers' => $state]))->assertOk()
            ->assertJsonPath('data.summary.total_raw', 15000);

        $voucher->forceFill(['is_active' => false])->save();
        $dropped = $this->api()->getJson('/api/buyer/cart?'.http_build_query(['vouchers' => $state]))->assertOk();
        $this->assertSame(20000, $dropped->json('data.summary.total_raw'));
        $this->assertNotNull($dropped->json('data.summary.voucher_notice'));
        $this->assertSame([], (array) $dropped->json('data.vouchers_state'));
    }

    public function test_voucher_list_offers_claimable_shop_vouchers_for_cart_sellers(): void
    {
        $this->addToCart();
        Voucher::factory()->shop($this->seller)->create(['code' => 'SHOP10', 'is_claimable' => true]);
        Voucher::factory()->shop($this->seller)->create(['code' => 'HIDDEN', 'is_claimable' => false]);

        $codes = collect($this->api()->getJson('/api/buyer/vouchers')->assertOk()->json('data'))->pluck('code')->all();

        $this->assertSame(['SHOP10'], $codes);
    }

    public function test_per_buyer_voucher_limit_applies_without_a_web_session(): void
    {
        $this->addToCart();
        $voucher = Voucher::factory()->create(['code' => 'ONCE', 'value' => 1000, 'per_buyer_limit' => 1]);
        $this->usedVoucherOrder($voucher);

        $this->api()->postJson('/api/buyer/cart/vouchers', ['code' => 'ONCE'])
            ->assertStatus(422)->assertJsonPath('message', 'You have already used this voucher the maximum number of times.');
    }

    // ── Addresses ──────────────────────────────────────────────────────────

    public function test_address_book_crud_with_ownership_and_default_guard(): void
    {
        $created = $this->api()->postJson('/api/buyer/addresses', [
            'address_mode' => 'manual', 'municipality_name' => 'San Fernando', 'street' => 'Office Avenue',
        ])->assertCreated()->assertJsonPath('data.is_default', false);
        $id = $created->json('data.id');

        $this->api()->postJson('/api/buyer/addresses', ['address_mode' => 'api'])->assertStatus(422)->assertJsonValidationErrors(['street', 'province']);
        $this->api($this->otherBuyer())->deleteJson("/api/buyer/addresses/{$id}")->assertNotFound();

        $default = BuyerAddress::query()->where('buyer_id', $this->buyer->id)->where('is_default', true)->sole();
        $this->api()->deleteJson("/api/buyer/addresses/{$default->id}")->assertStatus(422);

        $this->api()->postJson("/api/buyer/addresses/{$id}/default")->assertOk()->assertJsonPath('data.is_default', true);
        $this->api()->deleteJson("/api/buyer/addresses/{$default->id}")->assertOk();
    }

    // ── Checkout (COD) ─────────────────────────────────────────────────────

    public function test_checkout_places_a_cod_order_priced_by_the_server(): void
    {
        $lineId = $this->addToCart(2);
        $quote = $this->quote([$lineId]);
        $this->assertSame('cod', $quote->json('data.payment_method'));
        $payable = $quote->json('data.totals.payable_total_minor');

        $order = $this->api()->postJson('/api/buyer/checkout', $this->placePayload([$lineId], ['total_minor' => 1, 'notes' => [$this->seller->id => 'Leave at the gate']]))
            ->assertCreated()
            ->assertJsonPath('data.payment_method', 'cod')
            ->json('data');

        $this->assertSame($payable, $order['total_minor']);
        $sellerOrder = SellerOrder::query()->where('order_id', $order['id'])->sole();
        $this->assertSame(OrderStatus::Placed, $sellerOrder->status);
        $this->assertSame('Leave at the gate', $sellerOrder->buyer_note);
        $payment = Payment::query()->where('seller_order_id', $sellerOrder->id)->sole();
        $this->assertSame(PaymentMethod::Cod, $payment->method);
        $this->assertSame($payable, (int) $payment->amount_minor);
        $this->assertSame(3, $this->variant->fresh()->stock_quantity);
        $this->assertDatabaseMissing('cart_items', ['id' => $lineId]);
    }

    public function test_a_repeated_checkout_submit_cannot_create_a_second_order(): void
    {
        $lineId = $this->addToCart();
        $payload = $this->placePayload([$lineId]);

        $this->api()->postJson('/api/buyer/checkout', $payload)->assertCreated();

        // Retry with the same token (double tap / lost response) after re-adding the item.
        CartItem::factory()->create(['id' => $lineId, 'buyer_id' => $this->buyer->id, 'product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 1]);
        $this->api()->postJson('/api/buyer/checkout', $payload)->assertStatus(409);

        $this->assertSame(1, Order::query()->where('buyer_id', $this->buyer->id)->count());
    }

    public function test_checkout_reconciliation_is_owner_scoped_and_closes_an_unused_token(): void
    {
        $lineId = $this->addToCart();
        $payload = $this->placePayload([$lineId]);
        $token = ['checkout_token' => $payload['checkout_token']];
        $endpoint = '/api/buyer/checkout/reconcile';

        $this->api($this->otherBuyer())->postJson($endpoint, $token)->assertNotFound();
        $this->api()->postJson($endpoint, $token)->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->api()->postJson($endpoint, $token)->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->api()->postJson('/api/buyer/checkout', $payload)->assertConflict();
        $this->assertDatabaseCount('orders', 0);

        $payload = $this->placePayload([$lineId]);
        $placed = $this->api()->postJson('/api/buyer/checkout', $payload)->assertCreated();
        $this->api()->postJson($endpoint, ['checkout_token' => $payload['checkout_token']])
            ->assertOk()->assertJsonPath('data.status', 'placed')
            ->assertJsonPath('data.order.id', $placed->json('data.id'));
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_a_price_change_after_review_is_a_409_and_writes_nothing(): void
    {
        $lineId = $this->addToCart();
        $payload = $this->placePayload([$lineId]);

        $this->variant->forceFill(['price' => 150.00])->save();

        $this->api()->postJson('/api/buyer/checkout', $payload)->assertStatus(409);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(5, $this->variant->fresh()->stock_quantity);
    }

    public function test_client_shipping_fees_are_rejected(): void
    {
        $lineId = $this->addToCart();

        $this->api()->postJson('/api/buyer/checkout', $this->placePayload([$lineId], ['shipping_fees' => [$this->seller->id => 0]]))
            ->assertStatus(422)->assertJsonValidationErrors('shipping');
        $this->assertSame(0, Order::query()->count());
    }

    public function test_checkout_cannot_use_another_buyers_cart_line(): void
    {
        $stranger = $this->otherBuyer();
        $theirs = CartItem::factory()->create(['buyer_id' => $stranger->id, 'product_id' => $this->product->id, 'variant_id' => $this->variant->id, 'quantity' => 1]);

        $this->api()->postJson('/api/buyer/checkout/quote', ['item_ids' => [$theirs->id]])->assertStatus(409);
    }

    // ── Orders: cancel ─────────────────────────────────────────────────────

    public function test_cancel_before_acceptance_then_stale_repeat_is_409(): void
    {
        $lineId = $this->addToCart();
        $orderId = $this->api()->postJson('/api/buyer/checkout', $this->placePayload([$lineId]))->assertCreated()->json('data.id');
        $shipment = SellerOrder::query()->where('order_id', $orderId)->sole();

        $this->api($this->otherBuyer())->postJson("/api/buyer/orders/{$orderId}/shipments/{$shipment->id}/cancel", ['reason' => 'Changed my mind'])->assertNotFound();
        $this->api()->postJson("/api/buyer/orders/{$orderId}/shipments/{$shipment->id}/cancel", [])->assertStatus(422);

        $this->api()->postJson("/api/buyer/orders/{$orderId}/shipments/{$shipment->id}/cancel", ['reason' => 'Changed my mind'])
            ->assertOk()->assertJsonPath('data.status', OrderStatus::Cancelled->value);
        $this->assertSame(5, $this->variant->fresh()->stock_quantity);

        $this->api()->postJson("/api/buyer/orders/{$orderId}/shipments/{$shipment->id}/cancel", ['reason' => 'Again'])
            ->assertStatus(409)->assertJsonPath('data.status', OrderStatus::Cancelled->value);

        $this->api()->getJson('/api/buyer/orders')->assertOk()->assertJsonPath('data.data.0.id', $orderId);
        $this->api()->getJson("/api/buyer/orders/{$orderId}")->assertOk()->assertJsonPath('data.shipments.0.status', OrderStatus::Cancelled->value);
        $this->api($this->otherBuyer())->getJson("/api/buyer/orders/{$orderId}")->assertNotFound();
    }

    // ── Notifications / wishlist ───────────────────────────────────────────

    public function test_notifications_are_private_to_their_buyer(): void
    {
        $stranger = $this->otherBuyer();
        $mine = $this->notify($this->buyer, 'delivered');
        $this->notify($stranger, 'delivered');

        $this->api()->getJson('/api/buyer/notifications')->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.title', 'Order Delivered')
            ->assertJsonPath('data.meta.unread_count', 1);

        $this->api($stranger)->postJson("/api/buyer/notifications/{$mine->id}/read")->assertNotFound();
        $this->api()->postJson("/api/buyer/notifications/{$mine->id}/read")->assertOk()->assertJsonPath('data.unread_count', 0);
    }

    public function test_wishlist_save_and_remove_are_idempotent(): void
    {
        $this->api()->putJson('/api/buyer/wishlist/'.$this->product->id)->assertOk()->assertJsonPath('data.count', 1);
        $this->api()->putJson('/api/buyer/wishlist/'.$this->product->id)->assertOk()->assertJsonPath('data.count', 1);
        $this->api()->getJson('/api/buyer/wishlist')->assertOk()->assertJsonPath('data.data.0.product.id', $this->product->id);
        $this->api()->deleteJson('/api/buyer/wishlist/'.$this->product->id)->assertOk()->assertJsonPath('data.count', 0);
        $this->api()->putJson('/api/buyer/wishlist/999999')->assertNotFound();
        $this->assertSame(0, Wishlist::query()->count());
    }

    public function test_messages_are_limited_to_the_conversation_buyer(): void
    {
        $conversationId = $this->api()->postJson('/api/buyer/messages/open', ['seller_id' => $this->seller->id, 'product_id' => $this->product->id])
            ->assertOk()->json('data.conversation_id');

        $this->api()->postJson('/api/buyer/messages', ['conversation_id' => $conversationId])->assertStatus(422)->assertJsonValidationErrors('body');
        $this->api()->postJson('/api/buyer/messages', ['conversation_id' => $conversationId, 'body' => 'Is this in stock?'])
            ->assertCreated()->assertJsonPath('data.body', 'Is this in stock?');

        $this->api()->getJson("/api/buyer/messages/{$conversationId}")->assertOk()->assertJsonCount(1, 'data.messages');
        $stranger = $this->otherBuyer();
        $this->api($stranger)->getJson("/api/buyer/messages/{$conversationId}")->assertForbidden();
        $this->api($stranger)->postJson('/api/buyer/messages', ['conversation_id' => $conversationId, 'body' => 'hi'])->assertForbidden();
    }

    public function test_return_request_uses_the_web_validation_rules(): void
    {
        $lineId = $this->addToCart();
        $orderId = $this->api()->postJson('/api/buyer/checkout', $this->placePayload([$lineId]))->assertCreated()->json('data.id');
        $shipment = SellerOrder::query()->where('order_id', $orderId)->sole();

        $this->api()->postJson("/api/buyer/orders/{$orderId}/shipments/{$shipment->id}/return", ['reason' => 'Not a reason'])
            ->assertStatus(422)->assertJsonValidationErrors(['reason', 'description', 'photos']);
        $this->api($this->otherBuyer())->postJson("/api/buyer/orders/{$orderId}/shipments/{$shipment->id}/return", [])->assertNotFound();
    }

    public function test_refund_recording_is_separate_from_physical_return_and_scoped_to_the_buyer(): void
    {
        $lineId = $this->addToCart();
        $orderId = $this->api()->postJson('/api/buyer/checkout', $this->placePayload([$lineId]))->assertCreated()->json('data.id');
        $shipment = SellerOrder::query()->where('order_id', $orderId)->sole();
        $return = ParcelReturn::query()->forceCreate([
            'seller_order_id' => $shipment->id,
            'status' => ParcelReturnStatus::Completed,
            'reason' => 'Damaged item',
            'requested_at' => now(),
        ]);

        $url = "/api/buyer/orders/{$orderId}";
        $this->api()->getJson($url)->assertOk()
            ->assertJsonPath('data.shipments.0.return_status', ParcelReturnStatus::Completed->value)
            ->assertJsonPath('data.shipments.0.refund_recorded', false);

        $return->forceFill(['refunded_at' => now(), 'refund_reference' => 'private-reference'])->save();
        $this->api()->getJson('/api/buyer/orders')->assertOk()
            ->assertJsonPath('data.data.0.shipments.0.refund_recorded', true)
            ->assertJsonMissingPath('data.data.0.shipments.0.refund_reference');
        $this->api($this->otherBuyer())->getJson($url)->assertNotFound();
    }

    private function notify(Buyer $buyer, string $type): DatabaseNotification
    {
        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $buyer->getMorphClass(),
            'notifiable_id' => $buyer->id,
            'data' => ['type' => $type, 'message' => 'Hello'],
        ]);
    }

    private function usedVoucherOrder(Voucher $voucher): void
    {
        $lineId = CartItem::query()->where('buyer_id', $this->buyer->id)->value('id');
        $applied = $this->api()->postJson('/api/buyer/cart/vouchers', ['code' => $voucher->code])->assertOk();
        $payload = $this->placePayload([$lineId], ['vouchers' => $applied->json('data.vouchers_state')]);
        $quote = $this->quote([$lineId], ['vouchers' => $applied->json('data.vouchers_state')]);
        $payload['voucher_fingerprint'] = $quote->json('data.fingerprints.voucher');
        $payload['checkout_token'] = $quote->json('data.checkout_token');
        $this->api()->postJson('/api/buyer/checkout', $payload)->assertCreated();
        $this->addToCart();
    }
}
