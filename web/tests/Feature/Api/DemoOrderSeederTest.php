<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerOrder;
use Database\Seeders\DemoBuyerSeeder;
use Database\Seeders\DemoCatalogueSeeder;
use Database\Seeders\DemoOrderSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The demo history has to be a *real* history, and reach the app the way one does.
 *
 * Seeding order rows by hand would have been shorter, and it is exactly what must
 * not happen: the demo data would then be the only thing in the project that ever
 * exercised a hand-built order shape, and the day the checkout changed, the seeded
 * rows would quietly be the wrong shape while the Orders tab still looked fine.
 * These tests assert the properties that prove the rows came from the checkout
 * itself — real stock movements, an append-only ledger, and a buyer who can read
 * them back through the same endpoint the app calls.
 */
class DemoOrderSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_demo_buyer_ends_up_with_parcels_the_api_lists(): void
    {
        $this->seed(DemoOrderSeeder::class);

        $buyer = Buyer::query()->where('email', DemoBuyerSeeder::EMAIL)->sole();

        Sanctum::actingAs($buyer);

        $response = $this->getJson(route('api.orders.index'))->assertOk();

        // Two checkouts, and the first one deliberately spans two shops — which is
        // the one thing about a history that a single-shop fixture cannot show.
        $this->assertSame(2, Order::query()->where('buyer_id', $buyer->id)->count());

        $parcels = SellerOrder::query()
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->where('orders.buyer_id', $buyer->id)
            ->get();

        $this->assertCount(3, $parcels, 'one order across two shops plus one single-shop order');

        $this->assertCount(3, $response->json('data'));

        // Everything a card needs, present: a shop to name, an order number to read
        // back, and the product ids the app's joins depend on.
        foreach ($response->json('data') as $row) {
            $this->assertNotSame('', $row['shop']);
            $this->assertStringStartsWith('ORD-', $row['reference']);
            $this->assertSame('PLACED', $row['status']);
            $this->assertNotEmpty($row['product_ids']);
            $this->assertNotEmpty($row['items']);
        }
    }

    public function test_it_places_the_orders_through_the_checkout_rather_than_inserting_rows(): void
    {
        // The catalogue on its own, so the stock it seeds is the "before".
        $this->seed(DemoCatalogueSeeder::class);

        $before = (int) Product::query()->sum('stock_quantity');

        $this->seed(DemoOrderSeeder::class);

        $orderedUnits = (int) SellerOrder::query()
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->join('order_items', 'order_items.seller_order_id', '=', 'seller_orders.id')
            ->sum('order_items.quantity');

        $this->assertGreaterThan(0, $orderedUnits);

        // Stock really moved, by exactly the units ordered: the service's locked
        // decrement ran, rather than a row being written beside it.
        $this->assertSame($before - $orderedUnits, (int) Product::query()->sum('stock_quantity'));

        // And the append-only ledger says so, which hand-inserted rows would not.
        $ledger = InventoryTransaction::query()->where('reason', 'checkout')->get();

        $this->assertCount($orderedUnits, $ledger);
        $this->assertSame($orderedUnits, (int) $ledger->sum(fn ($row): int => abs($row->delta)));
    }

    public function test_seeding_twice_does_not_stack_a_second_demo_history(): void
    {
        $this->seed(DemoOrderSeeder::class);

        $orders = Order::query()->count();
        $stock = (int) Product::query()->sum('stock_quantity');

        $this->seed(DemoOrderSeeder::class);

        // Re-runnable, like every seeder beside it: a second pass neither mints
        // another history nor consumes more stock.
        $this->assertSame($orders, Order::query()->count());
        $this->assertSame($stock, (int) Product::query()->sum('stock_quantity'));
    }

    public function test_it_refuses_to_consume_stock_in_production(): void
    {
        $this->seed(DemoCatalogueSeeder::class);
        $this->seed(DemoBuyerSeeder::class);

        $stock = (int) Product::query()->sum('stock_quantity');

        $this->app->detectEnvironment(fn (): string => 'production');

        // Called directly rather than through `$this->seed()`: `db:seed` refuses to
        // run in production without `--force`, which would test the command instead
        // of the seeder's own guard.
        (new DemoOrderSeeder)->run();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame($stock, (int) Product::query()->sum('stock_quantity'));
    }
}
