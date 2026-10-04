<?php

namespace Database\Seeders;

use App\Models\Buyer\Buyer;
use App\Models\Order;
use App\Models\Product;
use App\Services\OrderPlacer;
use Illuminate\Database\Seeder;

/**
 * Two orders for the demo buyer, so the app's Orders tab has something to show.
 *
 * ## Why this exists, and why it is the proof the extraction was worth doing
 *
 * The catalogue and the buyer were seeded, and `GET /api/orders` returned `[]` —
 * which the app renders as its honest "no orders yet" empty state, so the one live
 * read that is *about a person* could not be verified on a device at all. The tab
 * only becomes checkable once a buyer actually has parcels.
 *
 * It places them through `App\Services\OrderPlacer` — the same service the web
 * checkout and `POST /api/orders` call — rather than inserting rows by hand. That
 * is deliberate and it is the payoff of extracting those rules: the seeded orders
 * carry real prices from the locked rows, a real stock decrement with its ledger
 * entry, real payments, a real address snapshot and a real `ORD-…` reference,
 * because they are written by the code that writes orders. Hand-inserted rows
 * would be a second spelling of an order, and the demo data would be the only
 * thing that ever checked it.
 *
 * The first order spans **two shops on purpose**: it is the one thing about the
 * history that a single-shop fixture cannot demonstrate, and it is what proves
 * `OrderResource`'s seller-order keying on a real screen — two parcels, one order
 * number, the way a real checkout produces them.
 *
 * ## Two rules, borrowed from the seeder beside it
 *
 *  * **It refuses to run in production**, for a different reason than the
 *    credential does: this one *consumes stock*. A production database must not
 *    have inventory decremented and orders minted that nobody placed.
 *  * **It is idempotent**, keyed on the buyer already owning an order. Re-seeding
 *    never stacks a second demo history on top of the first, so `db:seed` stays
 *    safe to run repeatedly — and it says so instead of guessing.
 */
class DemoOrderSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn(
                'DemoOrderSeeder: skipped — demo orders consume stock and must never be written in production.'
            );

            return;
        }

        // Setup, but only what is missing. Calling these unconditionally looked
        // harmless ("both are idempotent") and was not: the catalogue seeder
        // *resets* the catalogue, so a second `db:seed` handed stock back to the
        // orders the first pass had placed — and, where it recreates rows, would
        // leave the demo history pointing at products that no longer exist. A
        // seeder that places orders must not re-lay the ground under them.
        if (! Buyer::query()->where('email', DemoBuyerSeeder::EMAIL)->exists()) {
            $this->call(DemoBuyerSeeder::class);
        }

        $buyer = Buyer::query()->where('email', DemoBuyerSeeder::EMAIL)->first();

        if ($buyer === null) {
            $this->command?->warn(
                'DemoOrderSeeder: skipped — the demo buyer is missing, so there is nobody to place an order for.'
            );

            return;
        }

        if (! $this->hasBuyableProducts()) {
            $this->call(DemoCatalogueSeeder::class);
        }

        if (Order::query()->where('buyer_id', $buyer->id)->exists()) {
            $this->command?->info('Demo orders: already present — left alone.');

            return;
        }

        // Buyable products, grouped by shop, newest shop order last so the pick is
        // stable. Nothing is hard-coded to a product or a price: the prices come
        // from the rows, inside the service, exactly as they do for a real basket.
        $bySeller = Product::query()
            ->where('status', 'active')
            ->where('stock_quantity', '>', 0)
            ->orderBy('id')
            ->get()
            ->groupBy('seller_id')
            ->filter(fn ($products) => $products->isNotEmpty())
            ->values();

        if ($bySeller->count() < 2) {
            $this->command?->warn(
                'DemoOrderSeeder: skipped — a two-shop order needs two shops with buyable stock.'
            );

            return;
        }

        $placer = app(OrderPlacer::class);

        // One checkout, two shops: the two-parcel case the history screen is built
        // around. Quantity 1 each, so a demo never eats a shop's whole stock.
        $acrossTwoShops = $placer->place($buyer, [
            ['product_id' => $bySeller[0]->first()->id, 'quantity' => 1],
            ['product_id' => $bySeller[1]->first()->id, 'quantity' => 1],
        ]);

        // And a second, single-shop order, so the list is a list rather than one row
        // — the state the app's newest-first ordering is visible in. A second
        // product from the same shop when there is one, so the two orders do not
        // read as duplicates.
        $single = $bySeller[0]->count() > 1 ? $bySeller[0][1] : $bySeller[0]->first();

        $placer->place($buyer, [
            ['product_id' => $single->id, 'quantity' => 1],
        ]);

        $this->command?->info(sprintf(
            'Demo orders: 2 placed for %s (order %s has %d parcels across 2 shops). %s',
            DemoBuyerSeeder::EMAIL,
            $acrossTwoShops->reference,
            $acrossTwoShops->sellerOrders()->count(),
            "What the app's Orders tab will list.",
        ));
    }

    /**
     * Whether the shelves have anything on them at all.
     *
     * What is on them is not this seeder's business; it only needs to know whether
     * the catalogue is empty, because an empty one is the single case where laying
     * a catalogue down is the right move rather than a destructive one.
     */
    private function hasBuyableProducts(): bool
    {
        return Product::query()
            ->where('status', 'active')
            ->where('stock_quantity', '>', 0)
            ->exists();
    }
}
