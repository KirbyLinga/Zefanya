<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\Product;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Logistics\TransferCourier;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerShippingProvider;
use App\Models\Seller\StoreSale;
use App\Models\Shared\SellerOrder;
use App\Notifications\Buyer\DeliveryOtpCode;
use App\Services\Logistics\RoutePlannerService;
use App\Services\Seller\SellerShippingProviderService;
use Database\Seeders\DeliveryScenarioSeeder;
use Database\Seeders\Demo\DemoCatalogSeeder;
use Database\Seeders\Demo\DemoMarketplaceSeeder;
use Database\Seeders\Demo\DemoNetwork;
use Database\Seeders\Demo\DemoRoleAccountsSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * The demo marketplace as a whole: accounts, catalog, images, routing, and
 * real scan-secured delivery flows over the seeded network.
 */
class DemoMarketplaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const ALLOWED_LICENSES = '/^(CC0|CC BY(-SA)? [0-9.]+|Public domain|PDM)/i';

    public function test_safety_guard_refuses_the_live_database_and_production(): void
    {
        config(['database.default' => 'mysql', 'database.connections.mysql.driver' => 'mysql', 'database.connections.mysql.database' => 'Zefanya']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to seed database [Zefanya]');

        DemoMarketplaceSeeder::assertSafeTarget();
    }

    public function test_safety_guard_accepts_a_named_database_only_when_the_process_confirms_its_exact_name(): void
    {
        config(['database.default' => 'mysql', 'database.connections.mysql.driver' => 'mysql', 'database.connections.mysql.database' => 'Zefanya']);

        putenv('DEMO_SEED_CONFIRM_LIVE=SomethingElse');
        try {
            DemoMarketplaceSeeder::assertSafeTarget();
            $this->fail('A different name must not confirm the database.');
        } catch (RuntimeException) {
            putenv('DEMO_SEED_CONFIRM_LIVE=Zefanya');
            DemoMarketplaceSeeder::assertSafeTarget();
            $this->addToAssertionCount(1);
        } finally {
            putenv('DEMO_SEED_CONFIRM_LIVE');
        }
    }

    public function test_safety_guard_accepts_demo_and_isolated_test_databases(): void
    {
        foreach (['zefanya_demo', 'zefanya_concurrency_test'] as $name) {
            config(['database.default' => 'mysql', 'database.connections.mysql.driver' => 'mysql', 'database.connections.mysql.database' => $name]);
            DemoMarketplaceSeeder::assertSafeTarget();
        }

        $this->addToAssertionCount(1);
    }

    public function test_marketplace_seeds_accounts_catalog_images_routes_and_supports_delivery_flows(): void
    {
        Notification::fake();
        Storage::fake('public');
        Storage::fake('local');
        $this->withoutVite();
        putenv(DeliveryScenarioSeeder::MAILBOX_ENV);
        Admin::factory()->create();

        $this->seed(DemoMarketplaceSeeder::class);

        $this->assertAccounts();
        $this->assertCatalogAndImages();
        $this->assertRouting();
        $this->assertNoTransactionsWereCreated();

        $counts = $this->counts();
        $this->seed(DemoRoleAccountsSeeder::class);
        $this->seed(DemoCatalogSeeder::class);
        $this->assertSame($counts, $this->counts(), 'Re-running the account and catalog seeders must add nothing.');

        // Same municipality (no transfer), different municipality (station -> Province Hub -> station).
        $this->flow('sellerlaguna01', 'buyerlaguna02');
        $this->flow('sellerlaguna01', 'buyerlaguna03');
        // Cross-provider: Bulacan Express hands over to Metro Manila Express.
        $this->flow('sellerbulacan01', 'buyermetro01', expectedHandoffs: 1);
    }

    public function test_negative_checkout_blocks_unsupported_destination(): void
    {
        Notification::fake();
        Storage::fake('public');
        Storage::fake('local');
        $this->withoutVite();
        putenv(DeliveryScenarioSeeder::MAILBOX_ENV);
        Admin::factory()->create();

        $this->seed(DemoMarketplaceSeeder::class);

        $buyer = Buyer::query()->where('email', DemoNetwork::email(DemoRoleAccountsSeeder::UNSUPPORTED_BUYER['handle']))->firstOrFail();
        $this->assertSame('Alaminos', $buyer->municipality_name);
        $this->assertSame('Del Carmen', $buyer->barangay_name);
        $this->assertSame('043401001', $buyer->barangay_code);

        // No seller's carrier covers this barangay: checkout must not create an order.
        $seller = Seller::query()->where('email', DemoNetwork::email('sellerlaguna01'))->firstOrFail();
        $origin = DemoNetwork::provider('laguna');
        $product = Product::query()->where('seller_id', $seller->id)->whereDoesntHave('variants', fn ($q) => $q->whereNotNull('sku'))->orderBy('id')->firstOrFail();

        $this->actingAs($buyer, 'buyer')->get(route('buyer.products.show', $product->id))->assertOk()->assertSee('Add to Cart');
        $this->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 1])->assertOk()->assertJson(['success' => true]);
        $cart = CartItem::query()->where('buyer_id', $buyer->id)->where('product_id', $product->id)->sole();
        $this->get(route('buyer.checkout.index', ['items' => [$cart->id]]))->assertOk();

        $response = $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($buyer->id)->token,
            'carriers' => [$seller->id => $origin->id],
        ]);

        // Checkout must be blocked: redirect back to cart with error, no order created.
        $response->assertRedirect('/buyer/cart');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('seller_orders', 0);
        // Check that error flash message is in the session after redirect
        $this->followingRedirects()->get('/buyer/cart')->assertSee('No shipping carrier serves this route');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'sellers' => Seller::query()->count(), 'buyers' => Buyer::query()->count(), 'products' => Product::query()->count(),
            'variants' => DB::table('product_variants')->count(), 'images' => DB::table('product_images')->count(),
            'sales' => StoreSale::query()->count(), 'shipping' => SellerShippingProvider::query()->count(),
            'inventory' => DB::table('inventory_transactions')->count(),
        ];
    }

    private function assertAccounts(): void
    {
        $shops = json_decode((string) file_get_contents(database_path('seeders/data/demo/shops.json')), true);
        $this->assertCount(24, $shops);

        foreach ($shops as $shop) {
            $seller = Seller::query()->where('email', DemoNetwork::email($shop['handle']))->firstOrFail();
            $this->assertMatchesRegularExpression('/^[a-z0-9]+@zefanya\.test$/', $seller->email);
            $this->assertSame('approved', $seller->status);
            $this->assertTrue(Hash::check(DemoNetwork::PASSWORD, $seller->password));
            $this->assertNotEmpty($seller->store_description);
            Storage::disk('public')->assertExists($seller->store_logo_path);
            Storage::disk('public')->assertExists($seller->store_banner_path);
            foreach (['province_code', 'municipality_code', 'barangay_code', 'street', 'postal_code'] as $field) {
                $this->assertNotEmpty($seller->{$field}, $shop['handle'].' '.$field);
            }
            $provider = DemoNetwork::provider($shop['area']);
            $this->assertTrue(app(SellerShippingProviderService::class)->isEligible($seller, $provider), $shop['handle'].' pickup is covered');
            $this->assertTrue(SellerShippingProvider::query()->where('seller_id', $seller->id)->where('logistics_provider_id', $provider->id)->where('is_enabled', true)->exists());
        }

        foreach (DemoNetwork::areaKeys() as $area) {
            for ($n = 1; $n <= 4; $n++) {
                $buyer = Buyer::query()->where('email', DemoNetwork::email('buyer'.$area.sprintf('%02d', $n)))->firstOrFail();
                $this->assertMatchesRegularExpression('/^[a-z0-9]+@zefanya\.test$/', $buyer->email);
                $this->assertTrue(Hash::check(DemoNetwork::PASSWORD, $buyer->password));
                $this->assertNotNull($buyer->profilePhotoPath());
                Storage::disk('local')->assertExists($buyer->profilePhotoPath());
                $this->assertSame(1, $buyer->addresses()->where('is_default', true)->count());
                foreach (['province_code', 'municipality_code', 'barangay_code', 'street', 'postal_code'] as $field) {
                    $this->assertNotEmpty($buyer->{$field}, $buyer->email.' '.$field);
                }
            }
        }
    }

    private function assertCatalogAndImages(): void
    {
        $catalog = json_decode((string) file_get_contents(database_path('seeders/data/demo/catalog.json')), true)['themes'];
        $this->assertCount(8, $catalog);

        foreach ($catalog as $theme) {
            foreach ($theme['products'] as $item) {
                $this->assertNotEmpty($item['images'], $item['name']);
                foreach ($item['images'] as $image) {
                    $this->assertFileExists(database_path('seeders/data/demo/images/'.$image['file']));
                    $this->assertMatchesRegularExpression(self::ALLOWED_LICENSES, $image['license'], $image['file']);
                    $this->assertNotEmpty($image['author']);
                    $this->assertStringStartsWith('https://commons.wikimedia.org/', $image['source']);
                }
            }
        }

        $skus = [];
        foreach (Seller::query()->where('email', 'like', 'seller%@zefanya.test')->get() as $seller) {
            $products = Product::query()->where('seller_id', $seller->id)->with(['images', 'variants'])->get();
            $this->assertCount(4, $products, $seller->email);

            foreach ($products as $product) {
                $this->assertSame('active', $product->status);
                $this->assertGreaterThan(0, (float) $product->price);
                $this->assertGreaterThan(0, (int) $product->stock_quantity, $product->name);
                $this->assertNotEmpty($product->description);
                $this->assertGreaterThan(0, $product->weight_grams);
                $this->assertGreaterThanOrEqual(1, $product->images->count());
                foreach ($product->images as $image) {
                    Storage::disk('public')->assertExists($image->path);
                }
                foreach ($product->variants as $variant) {
                    if ($variant->sku !== null) {
                        $this->assertNotContains($variant->sku, $skus);
                        $skus[] = $variant->sku;
                    }
                }
            }
        }

        $this->assertSame(24, StoreSale::query()->count());
    }

    private function assertRouting(): void
    {
        $planner = app(RoutePlannerService::class);
        $legs = function (string $sellerHandle, string $buyerHandle) use ($planner): array {
            $seller = Seller::query()->where('email', DemoNetwork::email($sellerHandle))->firstOrFail();
            $buyer = Buyer::query()->where('email', DemoNetwork::email($buyerHandle))->firstOrFail();
            $provider = DemoNetwork::provider(preg_replace('/^seller|\d+$/', '', $sellerHandle));

            return $planner->planRoute($seller, $provider, (string) $buyer->barangay_code, (string) $buyer->province_code)->legs;
        };

        // A same-barangay and a same-municipality buyer need no transfer; the others cross stations via the Province Hub.
        foreach (DemoNetwork::areaKeys() as $area) {
            $this->assertSame([], $legs('seller'.$area.'01', 'buyer'.$area.'01'), $area.' same barangay');
            $this->assertSame([], $legs('seller'.$area.'01', 'buyer'.$area.'02'), $area.' same municipality');
            $this->assertSame(['ROAD', 'ROAD'], array_column($legs('seller'.$area.'01', 'buyer'.$area.'03'), 'transport_mode'), $area.' different municipality');
        }

        // Cross-provider, cross-province, and the sea and air links.
        $this->assertSame(['ROAD'], array_column($legs('sellerbulacan01', 'buyermetro01'), 'transport_mode'), 'the original direct Malolos to Quezon City road stays the shortest');
        $this->assertContains('SEA', array_column($legs('sellerbatangas01', 'buyercebu01'), 'transport_mode'));
        $this->assertContains('AIR', array_column($legs('sellermetro01', 'buyercebu01'), 'transport_mode'));
        $this->assertSame(['ROAD', 'ROAD', 'ROAD', 'ROAD'], array_column($legs('sellerlaguna01', 'buyerbulacan01'), 'transport_mode'));
        $this->assertContains('SEA', array_column($legs('sellerdavao01', 'buyercebu01'), 'transport_mode'));
    }

    private function assertNoTransactionsWereCreated(): void
    {
        foreach (['orders', 'seller_orders', 'payments', 'order_items', 'parcel_routes', 'parcel_handoffs', 'manifests'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function flow(string $sellerHandle, string $buyerHandle, int $expectedHandoffs = 0): void
    {
        $seller = Seller::query()->where('email', DemoNetwork::email($sellerHandle))->firstOrFail();
        $buyer = Buyer::query()->where('email', DemoNetwork::email($buyerHandle))->firstOrFail();
        $origin = DemoNetwork::provider(preg_replace('/^seller|\d+$/', '', $sellerHandle));
        $product = Product::query()->where('seller_id', $seller->id)->whereDoesntHave('variants', fn ($q) => $q->whereNotNull('sku'))->orderBy('id')->firstOrFail();
        $stock = $product->stock_quantity;

        $pickup = $this->riderAt($origin, (string) $seller->barangay_code);
        $parcel = $this->placeOrder($buyer, $seller, $origin, $product);
        $route = $parcel->parcelRoute;
        $destinationProvider = LogisticsHub::query()->findOrFail($route->destination_hub_id)->provider;
        $delivery = $this->riderAt($destinationProvider, (string) $buyer->barangay_code);

        $this->actingAs($seller, 'seller')->post(route('seller.orders.accept', $parcel))->assertSessionHasNoErrors();
        $this->post(route('seller.orders.pack', $parcel))->assertSessionHasNoErrors();
        $this->post(route('seller.orders.markReady', $parcel))->assertSessionHasNoErrors();
        $this->actingAs($origin, 'logistics')
            ->post(route('logistics.incoming-parcels.assignPickupRider', $parcel), ['courier_id' => $pickup->id, 'reason' => 'Origin collection'])
            ->assertSessionHasNoErrors();
        $this->post(route('rider.pickups.confirm', $parcel), ['verification_token' => $this->riderScan($pickup, $parcel)])->assertSessionHasNoErrors();
        $this->post(route('logistics.incoming-parcels.toSortingCenter', $parcel), ['verification_token' => $this->logisticsScan($origin, $parcel)])->assertSessionHasNoErrors();

        foreach ($route->fresh()->legs as $index => $leg) {
            $holder = $parcel->fresh()->custodyProvider;
            $to = LogisticsHub::query()->findOrFail($leg['hub_id']);

            if ((int) $to->logistics_provider_id === (int) $holder->id) {
                $this->moveByManifest($holder, $parcel, (int) $parcel->fresh()->custody_hub_id, $to);
            } else {
                $this->moveByHandoff($holder, $to->provider, $parcel);
            }

            $this->assertSame($to->id, $parcel->fresh()->custody_hub_id, 'Leg '.$index);
        }

        $destination = $delivery->provider;
        if ($route->legs !== []) {
            $this->actingAs($destination, 'logistics')
                ->post(route('logistics.parcel-sorting.sort', $parcel), ['verification_token' => $this->logisticsScan($destination, $parcel)])->assertSessionHasNoErrors();
        } else {
            $this->actingAs($destination, 'logistics');
        }
        $this->post(route('logistics.parcel-sorting.assignDelivery', $parcel), ['courier_id' => $delivery->id, 'reason' => 'Destination delivery'])->assertSessionHasNoErrors();
        $this->post(route('rider.deliveries.start', $parcel), ['verification_token' => $this->riderScan($delivery, $parcel)])->assertSessionHasNoErrors();
        $this->post(route('rider.deliveries.otp', $parcel), ['verification_token' => $this->riderScan($delivery, $parcel)])->assertSessionHasNoErrors();
        $code = Notification::sent($buyer, DeliveryOtpCode::class)->last()->code;
        $payment = $parcel->fresh()->payment;
        $this->post(route('rider.deliveries.deliver', $parcel), [
            'verification_token' => $this->riderScan($delivery, $parcel),
            'delivery_otp' => $code,
            'proof_photo' => UploadedFile::fake()->image('proof.jpg'),
            'received_by' => 'Buyer',
            'cod_amount' => number_format($payment->amount_minor / 100, 2, '.', ''),
        ])->assertSessionHasNoErrors();
        $this->assertSame(PaymentStatus::Paid, $payment->fresh()->status);
        $this->actingAs($buyer, 'buyer')->post(route('buyer.orders.confirmReceived', $parcel->order))->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Completed, $parcel->fresh()->status, $sellerHandle.' -> '.$buyerHandle);
        $this->assertSame($stock - 1, $product->fresh()->stock_quantity);
        $this->assertSame($expectedHandoffs, ParcelHandoff::query()->where('seller_order_id', $parcel->id)->count());
    }

    /** A seeded Rider of the company whose barangay claim covers the address. */
    private function riderAt(LogisticsProvider $provider, string $barangayCode): Courier
    {
        return Courier::query()->where('logistics_provider_id', $provider->id)
            ->whereHas('areas', fn ($q) => $q->where('barangay_code', $barangayCode))->with('rider')->orderBy('id')->firstOrFail();
    }

    private function placeOrder(Buyer $buyer, Seller $seller, LogisticsProvider $origin, Product $product): SellerOrder
    {
        $this->actingAs($buyer, 'buyer')->get(route('buyer.products.show', $product->id))->assertOk()->assertSee('Add to Cart');
        $this->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'quantity' => 1])->assertOk()->assertJson(['success' => true]);
        $cart = CartItem::query()->where('buyer_id', $buyer->id)->where('product_id', $product->id)->sole();
        $this->get(route('buyer.checkout.index', ['items' => [$cart->id]]))->assertOk();
        $this->post(route('buyer.checkout.store'), [
            'checkout_token' => CheckoutToken::issue($buyer->id)->token,
            'carriers' => [$seller->id => $origin->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        return SellerOrder::query()->where('seller_id', $seller->id)->latest('id')->firstOrFail();
    }

    private function moveByManifest(LogisticsProvider $owner, SellerOrder $parcel, int $from, LogisticsHub $to): void
    {
        $this->actingAs($owner, 'logistics')->post(route('logistics.manifests.store'), ['origin_hub_id' => $from, 'destination_hub_id' => $to->id])->assertSessionHasNoErrors();
        $manifest = Manifest::query()->latest('id')->firstOrFail();
        $this->post(route('logistics.manifests.items.store', $manifest), ['seller_order_id' => $parcel->id])->assertSessionHasNoErrors();
        $item = $manifest->items()->sole();

        // The sending company's own transport Courier is assigned before dispatch.
        if ($manifest->fresh()->transport_required) {
            $this->post(route('logistics.manifests.transport', $manifest), ['transfer_courier_id' => $this->transferCourier($owner)->id])->assertSessionHasNoErrors();
        }

        $scan = $this->postJson(route('logistics.manifests.scan.verify', $manifest), ['tracking_number' => $parcel->tracking_number, 'phase' => 'dispatch'])->assertOk()->json();
        $this->post(route('logistics.manifests.dispatch', $manifest), ['tokens' => [$item->id => $scan['verification_token']]])->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::InTransit, $parcel->fresh()->status);
        $this->assertSame($from, (int) $parcel->fresh()->custody_hub_id, 'Custody stays with the origin until the receiving scan.');

        $scan = $this->postJson(route('logistics.manifests.scan.verify', $manifest), ['tracking_number' => $parcel->tracking_number, 'phase' => 'receive'])->assertOk()->json();
        $this->post(route('logistics.manifests.receive.store', [$manifest, $item]), ['verification_token' => $scan['verification_token']])->assertSessionHasNoErrors();
    }

    private function moveByHandoff(LogisticsProvider $owner, LogisticsProvider $destination, SellerOrder $parcel): void
    {
        $this->actingAs($owner, 'logistics')->post(route('logistics.handoffs.store'), ['seller_order_id' => $parcel->id])->assertSessionHasNoErrors();
        $handoff = ParcelHandoff::query()->latest('id')->firstOrFail();
        $this->assertSame($destination->id, (int) $handoff->destination_provider_id);
        $this->actingAs($destination, 'logistics')->post(route('logistics.handoffs.accept', $handoff))->assertSessionHasNoErrors();
        if ($handoff->fresh()->transport_required) {
            $this->actingAs($owner, 'logistics')->post(route('logistics.handoffs.courier', $handoff), ['transfer_courier_id' => $this->transferCourier($owner)->id])->assertSessionHasNoErrors();
        }
        $scan = $this->actingAs($owner, 'logistics')->postJson(route('logistics.handoffs.scan.verify', $handoff), ['tracking_number' => $parcel->tracking_number, 'phase' => 'dispatch'])->assertOk()->json();
        $this->actingAs($owner, 'logistics')->post(route('logistics.handoffs.dispatch', $handoff), ['verification_token' => $scan['verification_token']])->assertSessionHasNoErrors();
        $scan = $this->actingAs($destination, 'logistics')->postJson(route('logistics.handoffs.scan.verify', $handoff), ['tracking_number' => $parcel->tracking_number, 'phase' => 'receive'])->assertOk()->json();
        $this->actingAs($destination, 'logistics')->post(route('logistics.handoffs.receive', $handoff), ['verification_token' => $scan['verification_token']])->assertSessionHasNoErrors();
    }

    private function transferCourier(LogisticsProvider $provider): TransferCourier
    {
        return TransferCourier::query()->where('logistics_provider_id', $provider->id)->active()->orderBy('id')->firstOrFail();
    }

    private function riderScan(Courier $courier, SellerOrder $parcel): string
    {
        return $this->actingAs($courier->rider, 'rider')->postJson(route('rider.scan.verify'), ['tracking_number' => $parcel->tracking_number])->assertOk()->json('actions.0.verification_token');
    }

    private function logisticsScan(LogisticsProvider $provider, SellerOrder $parcel): string
    {
        return $this->actingAs($provider, 'logistics')->postJson(route('logistics.scan.verify'), ['tracking_number' => $parcel->tracking_number])->assertOk()->json('actions.0.verification_token');
    }
}
