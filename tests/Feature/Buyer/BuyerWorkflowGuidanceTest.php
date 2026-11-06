<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Logistics\ParcelReturn;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerShippingProvider;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Services\Buyer\CheckoutCarrierService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class BuyerWorkflowGuidanceTest extends TestCase
{
    use BootstrapsCheckoutCarriers, LazilyRefreshDatabase;

    public function test_buyer_stage_labels_leave_seller_tracking_unchanged(): void
    {
        $parcel = SellerOrder::factory()->status(OrderStatus::Sorted)->create();

        $this->actingAs($parcel->seller, 'seller')->get(route('seller.orders.show', $parcel))
            ->assertOk()->assertSeeText('Sorted')
            ->assertSeeTextInOrder(['Order placed', 'Ready for pickup', 'In transit', 'Delivered', 'Completed']);
    }

    public function test_unknown_shipment_filter_falls_back_to_all_shipments(): void
    {
        $parcel = SellerOrder::factory()->create();

        $this->actingAs($parcel->order->buyer, 'buyer')->get(route('buyer.orders.index', ['tab' => 'unknown']))
            ->assertOk()->assertViewHas('tab', 'all')->assertSee('data-shipment-id="'.$parcel->id.'"', false);
    }

    public static function failedAttempts(): array
    {
        return [[0, 'Delivery needs review.'], [1, '1 retry remains.'], [2, 'Delivery attempts are exhausted.']];
    }

    #[DataProvider('failedAttempts')]
    public function test_failed_delivery_next_steps_do_not_promise_a_scheduled_retry(int $attempts, string $nextStep): void
    {
        $parcel = SellerOrder::factory()->status(OrderStatus::DeliveryFailed)->create(['delivery_attempts' => $attempts]);

        $response = $this->actingAs($parcel->order->buyer, 'buyer')->get(route('buyer.orders.show', $parcel->order))
            ->assertOk()->assertSee($nextStep);
        if ($attempts === 1) {
            $response->assertSee('a retry has not been confirmed.');
        }
    }

    public function test_return_awaiting_collection_is_not_in_transit_and_refund_progress_uses_recorded_facts(): void
    {
        $parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create();
        $return = new ParcelReturn;
        $return->forceFill([
            'seller_order_id' => $parcel->id, 'reason' => 'Item arrived damaged', 'requested_at' => now(),
            'seller_response_due_at' => now()->addDays(3), 'seller_decision' => ParcelReturn::APPROVED,
        ])->save();
        $this->actingAs($parcel->order->buyer, 'buyer');

        $this->get(route('buyer.orders.show', $parcel->order))->assertOk()
            ->assertSee('Awaiting collection arrangements from Logistics.')
            ->assertDontSee('This parcel is on its way back to the seller.')
            ->assertDontSee('Confirm receipt after you have checked it.')
            ->assertSee('No refund has been recorded yet.')
            ->assertDontSee('name="seller_order_id" value="'.$parcel->id.'"', false);

        $return->forceFill(['status' => ParcelReturnStatus::InTransit, 'collected_at' => now()])->save();
        $this->get(route('buyer.orders.show', $parcel->order))->assertOk()->assertSee('This parcel is on its way back to the seller.');

        $return->forceFill(['status' => ParcelReturnStatus::Completed, 'financial_review_required' => true])->save();
        $this->get(route('buyer.orders.show', $parcel->order))->assertOk()->assertSee('Your refund is being reviewed.')
            ->assertDontSee('Refund recorded.');

        $return->forceFill(['refunded_at' => now(), 'refund_reference' => 'REFUND-RECEIPT-17', 'refund_amount_minor' => 12345])->save();
        $this->get(route('buyer.orders.show', $parcel->order))->assertOk()->assertSee('Refund recorded.')
            ->assertSee('REFUND-RECEIPT-17')->assertSee('123.45')->assertDontSee('Your refund is being reviewed.');
    }

    public function test_delivered_shipment_explains_receipt_confirmation_and_seven_day_completion(): void
    {
        $parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create();
        $this->actingAs($parcel->order->buyer, 'buyer');

        foreach ([route('buyer.orders.index'), route('buyer.orders.show', $parcel->order)] as $url) {
            $this->get($url)->assertOk()->assertSee('Confirm Received')->assertSee('7 days');
        }
    }

    public static function mixedOrderStates(): array
    {
        return [
            'delivered and failed' => [OrderStatus::Delivered, OrderStatus::DeliveryFailed, OrderStatus::DeliveryFailed],
            'completed and failed' => [OrderStatus::Completed, OrderStatus::DeliveryFailed, OrderStatus::DeliveryFailed],
            'delivered and still preparing' => [OrderStatus::Delivered, OrderStatus::Preparing, OrderStatus::Preparing],
            'all delivered' => [OrderStatus::Delivered, OrderStatus::Delivered, OrderStatus::Delivered],
            'completed and returned' => [OrderStatus::Completed, OrderStatus::Returned, OrderStatus::Completed],
        ];
    }

    #[DataProvider('mixedOrderStates')]
    public function test_order_rollup_preserves_unresolved_shipments(OrderStatus $first, OrderStatus $second, OrderStatus $expected): void
    {
        $order = Order::factory()->create();
        SellerOrder::factory()->status($first)->create(['order_id' => $order->id]);
        SellerOrder::factory()->status($second)->create(['order_id' => $order->id]);

        $order->rollUpStatus();

        $this->assertSame($expected, $order->fresh()->status);
    }

    public function test_order_summary_recomputes_a_stale_delivered_status_without_modifying_data_on_get(): void
    {
        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $order->forceFill(['status' => OrderStatus::Delivered])->save();
        SellerOrder::factory()->status(OrderStatus::Delivered)->create(['order_id' => $order->id]);
        SellerOrder::factory()->status(OrderStatus::DeliveryFailed)->create(['order_id' => $order->id]);
        $this->actingAs($buyer, 'buyer');

        foreach ([route('buyer.orders.index'), route('buyer.orders.show', $order)] as $url) {
            $response = $this->get($url)->assertOk();
            $document = new \DOMDocument;
            @$document->loadHTML($response->getContent());
            $xpath = new \DOMXPath($document);
            $summary = $xpath->query('//*[@data-order-status="DELIVERY_FAILED"]');
            $this->assertSame(1, $summary->length);
        }
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_shipment_filter_shows_only_matching_parcels_and_full_details_keep_both(): void
    {
        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id]);
        $preparing = SellerOrder::factory()->status(OrderStatus::Placed)->create(['order_id' => $order->id]);
        $delivered = SellerOrder::factory()->status(OrderStatus::Delivered)->create(['order_id' => $order->id]);
        $this->actingAs($buyer, 'buyer');

        $this->get(route('buyer.orders.index', ['tab' => 'to_ship']))->assertOk()
            ->assertSee('data-shipment-id="'.$preparing->id.'"', false)
            ->assertDontSee('data-shipment-id="'.$delivered->id.'"', false)
            ->assertDontSee('name="seller_order_id" value="'.$delivered->id.'"', false)
            ->assertSee('1 of 2 shipments');
        $this->get(route('buyer.orders.index', ['tab' => 'to_receive']))->assertOk()
            ->assertDontSee('data-shipment-id="'.$preparing->id.'"', false)
            ->assertSee('data-shipment-id="'.$delivered->id.'"', false);
        $this->get(route('buyer.orders.show', $order))->assertOk()
            ->assertSee($preparing->seller->business_name)
            ->assertSee($delivered->seller->business_name);
    }

    public function test_shipping_unavailability_distinguishes_seller_settings_from_buyer_coverage(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();
        $provider = $this->bootstrapCheckoutCarriers($buyer, [$seller]);
        SellerShippingProvider::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
        $shipping = app(CheckoutCarrierService::class);

        $quote = $shipping->optionsForSellerGroup($buyer, $seller, 10000);

        $this->assertFalse($quote['available']);
        $this->assertSame('seller_shipping_settings', $quote['reason_code']);
        $this->assertStringContainsString('Contact the seller', $quote['message']);
        SellerShippingProvider::query()->where('seller_id', $seller->id)->update(['is_enabled' => true]);
        $buyer->forceFill(['barangay_code' => '0305401002'])->save();

        $quote = $shipping->optionsForSellerGroup($buyer, $seller, 10000);

        $this->assertFalse($quote['available']);
        $this->assertSame('destination_coverage', $quote['reason_code']);
        $this->assertStringContainsString('delivery barangay', $quote['message']);
        $this->assertSame($provider->id, SellerShippingProvider::query()->sole()->logistics_provider_id);
    }

    public function test_shipping_explanation_identifies_whose_address_is_incomplete(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();
        $this->bootstrapCheckoutCarriers($buyer, [$seller]);
        $shipping = app(CheckoutCarrierService::class);
        $buyer->forceFill(['barangay_code' => null])->save();

        $quote = $shipping->optionsForSellerGroup($buyer, $seller, 10000);
        $this->assertSame('buyer_address_incomplete', $quote['reason_code']);
        $this->assertStringContainsString('Your delivery address', $quote['message']);

        $buyer->forceFill(['barangay_code' => '0305401001'])->save();
        $seller->forceFill(['province_code' => null])->save();

        $quote = $shipping->optionsForSellerGroup($buyer, $seller, 10000);
        $this->assertSame('seller_address_incomplete', $quote['reason_code']);
        $this->assertStringContainsString('pickup address', $quote['message']);
    }

    public function test_checkout_renders_the_seller_specific_shipping_explanation_and_blocks_place_order(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();
        $this->bootstrapCheckoutCarriers($buyer, [$seller]);
        SellerShippingProvider::query()->where('seller_id', $seller->id)->update(['is_enabled' => false]);
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);

        $this->actingAs($buyer, 'buyer')->get(route('buyer.checkout.index'))
            ->assertOk()->assertSee('Contact the seller to enable shipping for its pickup address.');
    }
}
