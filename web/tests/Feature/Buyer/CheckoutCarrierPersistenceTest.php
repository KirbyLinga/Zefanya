<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Logistics\LogisticsHub;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerShippingProvider;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Policies\SellerOrderFleetPolicy;
use App\Services\Buyer\CheckoutCarrierService;
use App\Services\Logistics\CourierAssignmentService;
use App\Services\Logistics\LogisticsHubService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class CheckoutCarrierPersistenceTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private function prepare(array $prices = [100]): array
    {
        $buyer = Buyer::factory()->create();
        $sellers = collect($prices)->map(fn () => Seller::factory()->create());
        $this->bootstrapCheckoutCarriers($buyer, $sellers);
        $lines = $sellers->map(function (Seller $seller, int $index) use ($buyer, $prices) {
            $product = Product::factory()->create(['seller_id' => $seller->id, 'price' => $prices[$index], 'stock_quantity' => 10]);

            return CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'quantity' => 1]);
        });
        $token = CheckoutToken::issue($buyer->id);
        $this->actingAs($buyer, 'buyer');
        session([CheckoutController::SESSION_KEY => $lines->pluck('id')->all()]);

        return [$buyer, $sellers, $lines, ['checkout_token' => $token->token, ...$this->checkoutCarrierPayload($sellers)]];
    }

    public function test_multi_seller_checkout_persists_independent_carriers_and_authoritative_fees(): void
    {
        [$buyer, $sellers, $lines, $payload] = $this->prepare([100, 1500]);
        $second = $this->makeOperationalCheckoutCarrier('Second', '0305401001');
        SellerShippingProvider::query()->create(['seller_id' => $sellers[1]->id, 'logistics_provider_id' => $second->id, 'is_enabled' => true]);
        $payload['carriers'][$sellers[1]->id] = $second->id;

        $this->post(route('buyer.checkout.store'), $payload)->assertSessionHasNoErrors();

        $order = Order::query()->sole();
        $this->assertSame(164900, $order->total_minor);
        $this->assertSame(164900, (int) Payment::query()->sum('amount_minor'));
        $parcels = $order->sellerOrders->keyBy('seller_id');
        $this->assertSame($this->checkoutCarrier->id, $parcels[$sellers[0]->id]->carrier_provider_id);
        $this->assertSame($second->id, $parcels[$sellers[1]->id]->carrier_provider_id);
        $this->assertSame(4900, $parcels[$sellers[0]->id]->shipping_fee_minor);
        $this->assertSame(0, $parcels[$sellers[1]->id]->shipping_fee_minor);
        foreach ($parcels as $parcel) {
            $this->assertSame(OrderStatus::Placed, $parcel->status);
            $this->assertNull($parcel->courier_id);
            $this->assertNull($parcel->delivery_courier_id);
        }
        $this->assertSame(0, DB::table('delivery_assignments')->count());
        $this->assertSame(0, DB::table('status_histories')->count());
    }

    public function test_route_disabled_after_initial_quote_rolls_back_checkout_and_token(): void
    {
        [$buyer, $sellers, $lines, $payload] = $this->prepare();
        $this->app->instance(CheckoutCarrierService::class, new class extends CheckoutCarrierService
        {
            public function validateSelectionForOrder(Buyer $buyer, Collection $sellerGroups, array $submitted, ?array $rateSnapshot = null): array
            {
                SellerShippingProvider::query()->whereIn('seller_id', $sellerGroups->pluck('seller.id'))->update(['is_enabled' => false]);

                return parent::validateSelectionForOrder($buyer, $sellerGroups, $submitted, $rateSnapshot);
            }
        });

        $this->post(route('buyer.checkout.store'), $payload)->assertSessionHas('error');

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('checkout_tokens', ['token' => $payload['checkout_token'], 'used' => false]);
        $this->assertSame(10, $lines[0]->product->variants()->sole()->stock_quantity);
    }

    public function test_a_fee_that_changes_inside_the_transaction_stops_the_order_for_reconfirmation(): void
    {
        [$buyer, $sellers, $lines, $payload] = $this->prepare();
        $this->app->instance(CheckoutCarrierService::class, new class extends CheckoutCarrierService
        {
            public function validateSelectionForOrder(Buyer $buyer, Collection $sellerGroups, array $submitted, ?array $rateSnapshot = null): array
            {
                $buyer->forceFill(['municipality_code' => '030540200'])->save();

                return parent::validateSelectionForOrder($buyer, $sellerGroups, $submitted, $rateSnapshot);
            }
        });

        $this->post(route('buyer.checkout.store'), $payload)
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Shipping fees changed'));

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, SellerOrder::query()->count());
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_overlapping_provider_cannot_claim_selected_parcel_and_legacy_remains_unowned(): void
    {
        [$buyer, $sellers, $lines, $payload] = $this->prepare();
        $other = $this->makeOperationalCheckoutCarrier('Overlapping', '0305401001');
        $station = LogisticsHub::query()->where('logistics_provider_id', $other->id)->where('hub_type', LogisticsHub::STATION)->sole();
        $rider = Rider::factory()->create(['logistics_provider_id' => $other->id, 'station_hub_id' => $station->id, 'status' => 'approved', 'email_verified_at' => now()]);
        $courier = Courier::factory()->create(['logistics_provider_id' => $other->id, 'rider_id' => $rider->id, 'is_available' => true, 'max_weight_grams' => 10000]);
        $this->post(route('buyer.checkout.store'), $payload)->assertSessionHasNoErrors();
        $parcel = SellerOrder::query()->sole();
        $parcel->forceFill(['status' => OrderStatus::ReadyForPickup])->save();

        $this->assertTrue((new SellerOrderFleetPolicy)->advance($this->checkoutCarrier, $parcel));
        $this->assertFalse((new SellerOrderFleetPolicy)->advance($other, $parcel));
        $this->assertFalse((new LogisticsHubService)->servesOrigin($other, $parcel));
        $this->assertNull((new CourierAssignmentService)->assignPickup($parcel, $other));
        $this->assertNull($parcel->fresh()->courier_id);
        $this->assertTrue($courier->fresh()->is_available);
        $legacy = SellerOrder::factory()->create(['seller_id' => $sellers[0]->id]);
        $this->assertNull($legacy->fresh()->carrier_provider_id);
        $this->assertSame(0, $legacy->fresh()->shipping_fee_minor);
        $this->assertTrue((new SellerOrderFleetPolicy)->advance($other, $legacy));
    }
}
