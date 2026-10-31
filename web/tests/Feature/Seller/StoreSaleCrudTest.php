<?php

namespace Tests\Feature\Seller;

use App\Enums\StoreSaleStatus;
use App\Enums\StoreSaleType;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Seller\StoreSale;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StoreSaleCrudTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Seller $seller;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        // 08:00 in Manila.
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'UTC'));

        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->product = Product::factory()->create(['seller_id' => $this->seller->id, 'price' => 100.00]);
        ProductVariant::query()->where('product_id', $this->product->id)->update(['price' => 100.00]);

        $this->actingAs($this->seller, 'seller');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function createSale(array $overrides = []): TestResponse
    {
        return $this->post(route('seller.store-sales.store'), $overrides + [
            'product_id' => $this->product->id,
            'type' => 'percent',
            'percent' => 20,
            'starts_at' => '2026-11-02T10:00',
            'ends_at' => '2026-11-03T10:00',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function sale(array $attributes = []): StoreSale
    {
        return StoreSale::factory()->create($attributes + [
            'seller_id' => $this->seller->id,
            'product_id' => $this->product->id,
        ]);
    }

    public function test_seller_creates_a_percent_sale_with_times_read_as_manila(): void
    {
        $this->createSale()->assertRedirect(route('seller.store-sales.index'))->assertSessionHasNoErrors();

        $sale = StoreSale::sole();
        $this->assertSame($this->seller->id, $sale->seller_id);
        $this->assertSame(StoreSaleType::Percent, $sale->type);
        $this->assertSame(20, $sale->value);
        $this->assertSame('2026-11-02 02:00:00', $sale->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(StoreSaleStatus::Upcoming, $sale->status());
    }

    public function test_seller_creates_a_fixed_price_sale_on_a_variant(): void
    {
        $variant = ProductVariant::query()->where('product_id', $this->product->id)->firstOrFail();

        $this->createSale(['type' => 'fixed_price', 'percent' => null, 'fixed_price' => '79.50', 'variant_id' => $variant->id])
            ->assertSessionHasNoErrors();

        $sale = StoreSale::sole();
        $this->assertSame(7950, $sale->value);
        $this->assertSame($variant->id, $sale->variant_id);
    }

    public function test_discount_rules(): void
    {
        foreach ([0, 100, '12.5', null] as $percent) {
            $this->createSale(['percent' => $percent])->assertSessionHasErrors('percent');
        }

        foreach (['0', '1.234', '100', '100.01', null] as $price) {
            $this->createSale(['type' => 'fixed_price', 'fixed_price' => $price])->assertSessionHasErrors('fixed_price');
        }

        $this->createSale(['type' => 'bogus'])->assertSessionHasErrors('type');
        $this->assertSame(0, StoreSale::count());
    }

    public function test_whole_product_fixed_price_must_beat_the_cheapest_variant(): void
    {
        ProductVariant::factory()->create(['product_id' => $this->product->id, 'price' => 50.00]);

        $this->createSale(['type' => 'fixed_price', 'fixed_price' => '60.00'])->assertSessionHasErrors('fixed_price');
        $this->createSale(['type' => 'fixed_price', 'fixed_price' => '49.99'])->assertSessionHasNoErrors();
    }

    public function test_time_rules(): void
    {
        $this->createSale(['starts_at' => '2026-11-01T07:59'])->assertSessionHasErrors('starts_at');
        $this->createSale(['ends_at' => '2026-11-02T10:00'])->assertSessionHasErrors('ends_at');
        $this->createSale(['ends_at' => 'tomorrow'])->assertSessionHasErrors('ends_at');
        $this->assertSame(0, StoreSale::count());

        $this->createSale(['starts_at' => '2026-11-01T08:00'])->assertSessionHasNoErrors();
    }

    public function test_seller_cannot_use_another_sellers_product_or_a_mismatched_variant(): void
    {
        $other = Product::factory()->create(['seller_id' => Seller::factory()->create()->id]);
        $otherVariant = ProductVariant::query()->where('product_id', $other->id)->firstOrFail();

        $this->createSale(['product_id' => $other->id])->assertSessionHasErrors('product_id');
        $this->createSale(['variant_id' => $otherVariant->id])->assertSessionHasErrors('variant_id');
        $this->assertSame(0, StoreSale::count());
    }

    public function test_overlap_is_blocked_per_product_including_across_variants(): void
    {
        $this->createSale()->assertSessionHasNoErrors();
        $variant = ProductVariant::query()->where('product_id', $this->product->id)->firstOrFail();

        $overlaps = [
            ['starts_at' => '2026-11-02T09:00', 'ends_at' => '2026-11-02T11:00'],
            ['starts_at' => '2026-11-03T09:00', 'ends_at' => '2026-11-03T11:00'],
            ['starts_at' => '2026-11-02T12:00', 'ends_at' => '2026-11-02T13:00'],
            ['starts_at' => '2026-11-02T09:00', 'ends_at' => '2026-11-03T11:00'],
            ['starts_at' => '2026-11-02T12:00', 'ends_at' => '2026-11-02T13:00', 'variant_id' => $variant->id],
        ];

        foreach ($overlaps as $times) {
            $this->createSale($times)->assertSessionHasErrors('sale');
        }

        $this->assertSame(1, StoreSale::count());
    }

    public function test_touching_sales_and_other_products_do_not_overlap(): void
    {
        $this->createSale()->assertSessionHasNoErrors();

        $this->createSale(['starts_at' => '2026-11-03T10:00', 'ends_at' => '2026-11-04T10:00'])->assertSessionHasNoErrors();
        $this->createSale(['starts_at' => '2026-11-01T10:00', 'ends_at' => '2026-11-02T10:00'])->assertSessionHasNoErrors();

        $other = Product::factory()->create(['seller_id' => $this->seller->id]);
        $this->createSale(['product_id' => $other->id])->assertSessionHasNoErrors();

        $this->assertSame(4, StoreSale::count());
    }

    public function test_running_sale_can_change_discount_and_end_but_not_product_or_start(): void
    {
        $other = Product::factory()->create(['seller_id' => $this->seller->id]);
        $sale = $this->sale();
        $startsAt = $sale->starts_at->format('Y-m-d H:i:s');

        $this->put(route('seller.store-sales.update', $sale), [
            'type' => 'percent',
            'percent' => 35,
            'ends_at' => '2026-11-10T10:00',
            'product_id' => $other->id,
            'starts_at' => '2027-01-01T10:00',
        ])->assertRedirect(route('seller.store-sales.index'))->assertSessionHasNoErrors();

        $sale->refresh();
        $this->assertSame(35, $sale->value);
        $this->assertSame('2026-11-10 02:00:00', $sale->ends_at->format('Y-m-d H:i:s'));
        $this->assertSame($this->product->id, $sale->product_id);
        $this->assertSame($startsAt, $sale->starts_at->format('Y-m-d H:i:s'));
    }

    public function test_running_sale_edit_is_overlap_checked_and_must_end_in_the_future(): void
    {
        $sale = $this->sale(['ends_at' => now()->addDay()]);
        $this->sale(['starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(4)]);

        $update = fn (string $endsAt) => $this->put(route('seller.store-sales.update', $sale), ['type' => 'percent', 'percent' => 20, 'ends_at' => $endsAt]);

        $update('2026-11-06T10:00')->assertSessionHasErrors('sale');
        $update('2026-11-01T07:00')->assertSessionHasErrors('ends_at');
        $update('2026-11-03T08:00')->assertSessionHasNoErrors();
    }

    public function test_editing_a_sale_does_not_clash_with_itself(): void
    {
        $sale = $this->sale(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);

        $this->put(route('seller.store-sales.update', $sale), [
            'product_id' => $this->product->id,
            'type' => 'fixed_price',
            'fixed_price' => '80.00',
            'starts_at' => '2026-11-02T08:00',
            'ends_at' => '2026-11-03T12:00',
        ])->assertSessionHasNoErrors();

        $this->assertSame(8000, $sale->fresh()->value);
        $this->assertSame(StoreSaleType::FixedPrice, $sale->fresh()->type);
    }

    public function test_end_now_stops_a_running_sale_and_frees_the_slot(): void
    {
        $sale = $this->sale();

        $this->post(route('seller.store-sales.end', $sale))->assertRedirect(route('seller.store-sales.index'))->assertSessionHasNoErrors();

        $this->assertSame(StoreSaleStatus::Ended, $sale->fresh()->status());
        $this->assertSame(0, StoreSale::query()->ongoing()->count());

        $this->createSale(['starts_at' => '2026-11-01T08:00', 'ends_at' => '2026-11-01T12:00'])->assertSessionHasNoErrors();
    }

    public function test_end_now_works_in_the_same_second_the_sale_started(): void
    {
        $sale = $this->sale(['starts_at' => now(), 'ends_at' => now()->addDay()]);

        $this->post(route('seller.store-sales.end', $sale))->assertSessionHasNoErrors();

        $this->assertTrue($sale->fresh()->ends_at->gt($sale->fresh()->starts_at));
        $this->assertTrue($sale->fresh()->ends_at->lte(now()->addSecond()));
    }

    public function test_end_now_only_applies_to_running_sales(): void
    {
        $upcoming = $this->sale(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
        $ended = $this->sale(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);

        foreach ([$upcoming, $ended] as $sale) {
            $endsAt = $sale->ends_at->format('Y-m-d H:i:s');

            $this->post(route('seller.store-sales.end', $sale))->assertSessionHasErrors('sale');

            $this->assertSame($endsAt, $sale->fresh()->ends_at->format('Y-m-d H:i:s'));
        }
    }

    public function test_ended_sale_cannot_be_edited(): void
    {
        $sale = $this->sale(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay(), 'value' => 20]);

        $this->get(route('seller.store-sales.edit', $sale))->assertRedirect(route('seller.store-sales.index'));

        $this->put(route('seller.store-sales.update', $sale), [
            'product_id' => $this->product->id,
            'type' => 'percent',
            'percent' => 50,
            'starts_at' => '2026-11-02T08:00',
            'ends_at' => '2026-11-03T08:00',
        ])->assertSessionHasErrors('sale');

        $this->assertSame(20, $sale->fresh()->value);
    }

    public function test_only_sales_not_running_can_be_deleted(): void
    {
        $running = $this->sale();
        $upcoming = $this->sale(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
        $ended = $this->sale(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);

        $this->delete(route('seller.store-sales.destroy', $running))->assertSessionHasErrors('sale');
        $this->assertNotNull($running->fresh());

        foreach ([$upcoming, $ended] as $sale) {
            $this->delete(route('seller.store-sales.destroy', $sale))->assertSessionHasNoErrors();
            $this->assertNull($sale->fresh());
        }
    }

    public function test_other_sellers_cannot_touch_a_sale_or_see_it(): void
    {
        $sale = $this->sale(['value' => 20]);
        $this->actingAs(Seller::factory()->create(['status' => 'approved']), 'seller');

        $this->get(route('seller.store-sales.edit', $sale))->assertForbidden();
        $this->put(route('seller.store-sales.update', $sale), ['type' => 'percent', 'percent' => 50, 'ends_at' => '2026-11-10T10:00'])->assertForbidden();
        $this->post(route('seller.store-sales.end', $sale))->assertForbidden();
        $this->delete(route('seller.store-sales.destroy', $sale))->assertForbidden();
        $this->get(route('seller.store-sales.index'))->assertOk()->assertDontSee($this->product->name);

        $this->assertSame(20, $sale->fresh()->value);
        $this->assertSame(StoreSaleStatus::Ongoing, $sale->fresh()->status());
    }

    public function test_guests_are_redirected(): void
    {
        auth('seller')->logout();

        foreach (['index', 'create'] as $page) {
            $this->get(route("seller.store-sales.{$page}"))->assertRedirect();
        }

        $this->createSale()->assertRedirect();
        $this->assertSame(0, StoreSale::count());
    }

    public function test_pages_show_each_status_with_the_right_actions(): void
    {
        $running = $this->sale();
        $upcoming = $this->sale(['starts_at' => now()->addDay(), 'ends_at' => now()->addDays(2)]);
        $ended = $this->sale(['starts_at' => now()->subDays(2), 'ends_at' => now()->subDay()]);

        $this->get(route('seller.store-sales.index'))
            ->assertOk()
            ->assertSee(['Ongoing', 'Upcoming', 'Ended', 'Store Sales'])
            ->assertSee('action="'.route('seller.store-sales.end', $running).'"', false)
            ->assertDontSee('action="'.route('seller.store-sales.end', $upcoming).'"', false)
            ->assertDontSee(route('seller.store-sales.edit', $ended), false)
            ->assertSee('action="'.route('seller.store-sales.destroy', $upcoming).'"', false)
            ->assertDontSee('action="'.route('seller.store-sales.destroy', $running).'"', false);

        $this->get(route('seller.store-sales.create'))->assertOk()->assertSee($this->product->name);

        $this->get(route('seller.store-sales.edit', $running))
            ->assertOk()
            ->assertSee('its product and start time are locked')
            ->assertDontSee('name="starts_at"', false)
            ->assertSee('End Now');

        $this->get(route('seller.store-sales.edit', $upcoming))
            ->assertOk()
            ->assertSee('name="starts_at"', false)
            ->assertDontSee('End Now');
    }

    public function test_index_shows_times_in_manila(): void
    {
        $this->sale(['starts_at' => '2026-11-02 02:00:00', 'ends_at' => '2026-11-03 02:00:00']);

        $this->get(route('seller.store-sales.index'))->assertSee('Nov 2, 2026 10:00 AM');
    }

    public function test_routes_are_only_the_ones_this_feature_needs(): void
    {
        $names = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter(fn ($name) => str_starts_with((string) $name, 'seller.store-sales.'))
            ->sort()->values()->all();

        $this->assertSame([
            'seller.store-sales.create',
            'seller.store-sales.destroy',
            'seller.store-sales.edit',
            'seller.store-sales.end',
            'seller.store-sales.index',
            'seller.store-sales.store',
            'seller.store-sales.update',
        ], $names);
    }
}
