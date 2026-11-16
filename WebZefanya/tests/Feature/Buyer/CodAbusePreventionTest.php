<?php

namespace Tests\Feature\Buyer;

use App\Enums\DeliveryFailureReason;
use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Exceptions\CheckoutException;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CodDeliveryStrike;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Buyer\CodRiskService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

/**
 * COD abuse prevention: three COD parcels whose delivery finally failed because of
 * the buyer, within 90 days and counted by account or phone, stop checkout until
 * an Admin restores cash on delivery.
 */
class CodAbusePreventionTest extends TestCase
{
    use BootstrapsCheckoutCarriers, CreatesAssignableFleet, LazilyRefreshDatabase;

    private Buyer $buyer;

    private Seller $seller;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buyer = Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now(), 'street' => 'Home Street', 'contact_no' => '09171234567']);
        $this->seller = Seller::factory()->create(['status' => 'approved']);
    }

    /** A COD parcel out for its final delivery attempt, which then fails for `$reason`. */
    private function failFinalAttempt(Buyer $buyer, DeliveryFailureReason $reason, ?string $phone = null): SellerOrder
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $courier = $this->assignableCourier($provider, [], 'Quezon City', '137404000');
        $parcel = SellerOrder::factory()->create([
            'order_id' => Order::factory()->create(['buyer_id' => $buyer->id, 'shipping_address' => ['phone' => $phone ?? $buyer->contact_no, 'barangay_code' => '1374040001']])->id,
            'seller_id' => $this->seller->id, 'status' => OrderStatus::OutForDelivery->value,
            'courier_id' => $courier->id, 'delivery_courier_id' => $courier->id, 'delivery_attempts' => 1,
            'origin_station_hub_id' => $courier->rider->station_hub_id, 'destination_area_id' => $courier->area_id,
            'delivery_failure_reason' => $reason->value,
        ])->fresh();
        Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 10000]);

        return app(SellerOrderStatusService::class)->transition($parcel, OrderStatus::DeliveryFailed, actor: OrderActor::Rider);
    }

    private function strikes(int $count, ?Buyer $buyer = null, ?string $phone = null): void
    {
        foreach (range(1, $count) as $i) {
            $this->failFinalAttempt($buyer ?? $this->buyer, DeliveryFailureReason::RefusedByRecipient, $phone);
        }
    }

    private function api(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->buyer->createToken('api-token', ['buyer'], now()->addHour())->plainTextToken)->withHeader('Accept', 'application/json');
    }

    private function cartLine(): int
    {
        $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);
        BuyerAddress::query()->create([
            'buyer_id' => $this->buyer->id, 'address_mode' => 'api', 'province_code' => '030540000', 'province_name' => 'Pampanga',
            'municipality_code' => '030540100', 'municipality_name' => 'San Fernando', 'barangay_code' => '0305401001', 'barangay_name' => 'First',
            'street' => 'Home Street', 'is_default' => true, 'default_marker' => 1,
        ]);
        $product = Product::factory()->create(['seller_id' => $this->seller->id, 'price' => 100, 'stock_quantity' => 5, 'status' => 'active', 'weight_grams' => 500]);
        $variant = ProductVariant::query()->where('product_id', $product->id)->firstOrFail();
        $variant->forceFill(['price' => 100, 'stock_quantity' => 5, 'is_available' => true, 'weight_grams' => null])->save();

        return CartItem::factory()->create(['buyer_id' => $this->buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1])->id;
    }

    /** @return array<string, array{DeliveryFailureReason, bool}> */
    public static function reasons(): array
    {
        return [
            'refused' => [DeliveryFailureReason::RefusedByRecipient, true],
            'unreachable' => [DeliveryFailureReason::UnreachableByPhone, true],
            'unavailable' => [DeliveryFailureReason::RecipientUnavailable, true],
            'wrong address' => [DeliveryFailureReason::WrongAddress, true],
            'damaged parcel' => [DeliveryFailureReason::DamagedParcel, false],
            'other' => [DeliveryFailureReason::Other, false],
        ];
    }

    #[DataProvider('reasons')]
    public function test_only_buyer_caused_final_failures_become_strikes(DeliveryFailureReason $reason, bool $counts): void
    {
        $parcel = $this->failFinalAttempt($this->buyer, $reason);

        $this->assertSame(2, $parcel->delivery_attempts);
        $this->assertSame($counts ? 1 : 0, CodDeliveryStrike::query()->count());
        if ($counts) {
            $this->assertSame('9171234567', CodDeliveryStrike::query()->sole()->phone_key);
        }
    }

    public function test_a_first_failed_attempt_with_a_retry_left_is_not_a_strike(): void
    {
        $parcel = SellerOrder::factory()->create([
            'order_id' => Order::factory()->create(['buyer_id' => $this->buyer->id])->id,
            'status' => OrderStatus::OutForDelivery->value, 'delivery_attempts' => 0,
            'delivery_failure_reason' => DeliveryFailureReason::RefusedByRecipient->value,
        ]);
        Payment::factory()->create(['seller_order_id' => $parcel->id]);

        app(SellerOrderStatusService::class)->transition($parcel->fresh(), OrderStatus::DeliveryFailed, actor: OrderActor::Rider);

        $this->assertSame(0, CodDeliveryStrike::query()->count());
    }

    public function test_three_strikes_block_web_and_app_checkout_and_two_do_not(): void
    {
        $line = $this->cartLine();
        $this->strikes(2);
        $this->assertFalse(app(CodRiskService::class)->isBlocked($this->buyer));
        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.checkout.index', ['items' => [$line]]))->assertOk();

        $this->strikes(1);

        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.checkout.index', ['items' => [$line]]))
            ->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error', CodRiskService::BLOCKED_MESSAGE);
        $this->post(route('buyer.checkout.store'), ['items' => [$line]])->assertRedirect();
        $this->api()->postJson('/api/buyer/checkout/quote', ['item_ids' => [$line]] + $this->checkoutCarrierPayload([$this->seller]))
            ->assertForbidden()->assertJsonPath('message', CodRiskService::BLOCKED_MESSAGE);
        $this->assertSame(0, Order::query()->whereDoesntHave('sellerOrders', fn ($q) => $q->whereNotNull('delivery_failure_reason'))->count(), 'No new order was placed.');
    }

    public function test_the_service_guard_stops_order_placement_even_without_the_page_check(): void
    {
        $this->strikes(3);

        $this->expectException(CheckoutException::class);
        $this->expectExceptionMessage(CodRiskService::BLOCKED_MESSAGE);
        app(CodRiskService::class)->assertCanCheckout($this->buyer);
    }

    public function test_strikes_older_than_ninety_days_stop_counting(): void
    {
        $this->strikes(3);
        CodDeliveryStrike::query()->update(['failed_at' => now()->subDays(91)]);

        $this->assertSame(0, app(CodRiskService::class)->strikeCount($this->buyer));
        $this->assertFalse(app(CodRiskService::class)->isBlocked($this->buyer));
    }

    public function test_the_block_follows_the_phone_number_to_another_account(): void
    {
        $original = Buyer::factory()->create(['status' => 'approved', 'contact_no' => '0917 123 4567']);
        $this->strikes(3, $original);

        // A new account registered with the same number, written another way.
        $newAccount = Buyer::factory()->create(['status' => 'approved', 'contact_no' => '+63 917 123 4567']);
        $this->assertTrue(app(CodRiskService::class)->isBlocked($newAccount));

        // Changing the account's own number does not escape its own history.
        $original->forceFill(['contact_no' => '09998887777'])->save();
        $this->assertTrue(app(CodRiskService::class)->isBlocked($original->fresh()));
    }

    public function test_an_admin_restores_cod_with_a_reason_and_new_failures_count_from_zero(): void
    {
        $this->strikes(3);
        $admin = Admin::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'admin')->get(route('admin.users.index', ['type' => 'buyer']))
            ->assertOk()->assertSee('COD paused: 3 failed deliveries')->assertSee(route('admin.users.buyer.cod-lift', $this->buyer), false);
        $this->post(route('admin.users.buyer.cod-lift', $this->buyer), ['reason' => 'short'])->assertSessionHasErrors('reason');
        $this->post(route('admin.users.buyer.cod-lift', $this->buyer), ['reason' => 'Courier confirmed the buyer was never contacted.'])
            ->assertSessionHas('success');

        $risk = app(CodRiskService::class);
        $this->assertFalse($risk->isBlocked($this->buyer));
        $log = OperationsAuditLog::query()->where('action', 'buyer_cod_restriction_lifted')->sole();
        $this->assertSame('Courier confirmed the buyer was never contacted.', $log->reason);
        $this->assertSame(3, $log->old_values['strikes']);

        // A second lift is refused while not restricted; new failures count again.
        $this->post(route('admin.users.buyer.cod-lift', $this->buyer), ['reason' => 'Trying to lift it a second time.'])->assertSessionHasErrors('reason');
        $this->travel(1)->minutes();
        $this->strikes(2);
        $this->assertSame(2, $risk->strikeCount($this->buyer));
    }

    public function test_support_admins_cannot_restore_cod(): void
    {
        $this->strikes(3);

        $this->actingAs(Admin::factory()->create(['role' => 'support']), 'admin')
            ->post(route('admin.users.buyer.cod-lift', $this->buyer), ['reason' => 'Support tries to restore COD.'])->assertForbidden();
        $this->assertTrue(app(CodRiskService::class)->isBlocked($this->buyer));
    }

    public function test_existing_finally_failed_parcels_are_backfilled_once(): void
    {
        $parcel = SellerOrder::factory()->create([
            'order_id' => Order::factory()->create(['buyer_id' => $this->buyer->id, 'shipping_address' => ['phone' => '09171234567']])->id,
            'status' => OrderStatus::Returned->value, 'delivery_attempts' => 2,
            'delivery_failure_reason' => DeliveryFailureReason::UnreachableByPhone->value,
        ]);
        Payment::factory()->create(['seller_order_id' => $parcel->id]);

        app(CodRiskService::class)->backfill();
        app(CodRiskService::class)->backfill();

        $this->assertSame(1, CodDeliveryStrike::query()->count());
    }

    public function test_the_web_checkout_post_is_rate_limited(): void
    {
        $this->actingAs($this->buyer, 'buyer');
        foreach (range(1, 10) as $i) {
            $this->post(route('buyer.checkout.store'), [])->assertStatus(302);
        }
        $this->post(route('buyer.checkout.store'), [])->assertStatus(429);
    }
}
