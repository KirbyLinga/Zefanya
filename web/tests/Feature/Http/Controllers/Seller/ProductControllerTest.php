<?php

namespace Tests\Feature\Http\Controllers\Seller;

use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Seller $seller;

    private Seller $otherSeller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seller = Seller::factory()->create();
        $this->otherSeller = Seller::factory()->create();
    }

    private function mine(array $attributes = []): Product
    {
        return Product::factory()->create(['seller_id' => $this->seller->id] + $attributes);
    }

    // ── Index: ownership + isolation ────────────────────────────────────────

    public function test_a_seller_sees_only_their_own_products(): void
    {
        $mine = $this->mine(['name' => 'My Walnut Desk']);
        Product::factory()->create(['seller_id' => $this->otherSeller->id, 'name' => 'Their Oak Table']);

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index'))
            ->assertOk()
            ->assertSee('My Walnut Desk')
            ->assertDontSee('Their Oak Table');
    }

    public function test_soft_deleted_products_never_appear(): void
    {
        $kept = $this->mine(['name' => 'Kept Brass Lamp']);
        $gone = $this->mine(['name' => 'Gone Velvet Chair']);
        $gone->delete();

        $response = $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index'));

        $response->assertOk();
        $response->assertSee('Kept Brass Lamp');
        $response->assertDontSee('Gone Velvet Chair');
        $this->assertTrue($kept->fresh()->exists);
    }

    // ── Index: filters, sort, pagination ────────────────────────────────────

    public function test_search_filters_by_name(): void
    {
        $this->mine(['name' => 'Zebra Desk Unique']);
        $this->mine(['name' => 'Plain Lamp']);

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index', ['q' => 'Zebra Desk Unique']))
            ->assertOk()
            ->assertSee('Zebra Desk Unique')
            ->assertDontSee('Plain Lamp');
    }

    public function test_status_filter_shows_only_that_status(): void
    {
        $draft = $this->mine(['name' => 'Draft Item A', 'status' => 'draft']);
        $this->mine(['name' => 'Active Item B', 'status' => 'active']);

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index', ['status' => 'draft']))
            ->assertOk()
            ->assertSee('Draft Item A')
            ->assertDontSee('Active Item B');

        $this->assertSame('draft', $draft->fresh()->status);
    }

    public function test_sort_orders_by_price_ascending(): void
    {
        $cheap = $this->mine(['name' => 'Cheap Mug', 'price' => 5.00]);
        $this->mine(['name' => 'Pricey Desk', 'price' => 500.00]);

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index', ['sort' => 'price_asc']))
            ->assertOk()
            ->assertViewHas('products', function ($products) use ($cheap) {
                return $products->getCollection()->first()->id === $cheap->id;
            });
    }

    public function test_pagination_defaults_to_ten_per_page_and_keeps_the_query_string(): void
    {
        Product::factory()->count(25)->create(['seller_id' => $this->seller->id]);

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index', ['sort' => 'price_asc', 'page' => 2]))
            ->assertOk()
            ->assertViewHas('products', function ($products) {
                return $products->count() === 10
                    && $products->total() === 25
                    && str_contains($products->previousPageUrl(), 'sort=price_asc');
            });
    }

    public function test_per_page_accepts_the_allowed_sizes_and_rejects_anything_else(): void
    {
        Product::factory()->count(30)->create(['seller_id' => $this->seller->id]);

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index', ['per_page' => 25]))
            ->assertOk()
            ->assertViewHas('products', fn ($products) => $products->count() === 25 && $products->perPage() === 25);

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index', ['per_page' => 999]))
            ->assertOk()
            ->assertViewHas('products', fn ($products) => $products->perPage() === 10);
    }

    public function test_category_filter_limits_the_listing_to_that_category(): void
    {
        $category = Category::create(['icon' => 'flower-2', 'label' => 'Home and Garden']);
        $other = Category::create(['icon' => 'paw-print', 'label' => 'Pet Supplies']);

        $this->mine(['name' => 'Garden Hose', 'category_id' => $category->id]);
        $this->mine(['name' => 'Cat Collar', 'category_id' => $other->id]);

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index', ['category' => $category->id]))
            ->assertOk()
            ->assertSee('Garden Hose')
            ->assertDontSee('Cat Collar')
            ->assertViewHas('categories', fn ($categories) => $categories->pluck('id')->contains($category->id));
    }

    public function test_invalid_sort_and_status_values_are_ignored(): void
    {
        $this->mine(['name' => 'Alpha']);
        $this->mine(['name' => 'Beta']);

        $response = $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index', ['sort' => 'drop-table', 'status' => 'zzz']));

        $response->assertOk()
            ->assertViewHas('sort', 'newest')
            ->assertViewHas('products', fn ($products) => $products->total() === 2);
    }

    // ── Redesigned listing shell ─────────────────────────────────────────────

    public function test_the_listing_renders_the_header_filters_and_table_shell(): void
    {
        $this->mine(['name' => 'Linen Lamp', 'status' => 'active', 'stock_quantity' => 3]);

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index'))
            ->assertOk()
            ->assertSee('Manage your product listings.')
            ->assertSee('Export CSV')
            ->assertSee('Bulk Actions')
            ->assertSee('Active filter:')
            ->assertSee('id="spProductFilters"', false)
            ->assertSee('All categories')
            ->assertSee('data-auto-submit', false)
            ->assertSee('data-select-all', false)
            ->assertSee('data-product-create-open', false)
            ->assertSee('Linen Lamp')
            ->assertSee('3 left (Low)')
            ->assertSee('sp-pill--amber', false)
            ->assertSee('Showing 1-1 of 1 product')
            ->assertSee('Rows:');
    }

    public function test_out_of_stock_rows_render_the_out_of_stock_status(): void
    {
        $this->mine(['name' => 'Sold Out Vase', 'status' => 'active', 'stock_quantity' => 0]);

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index'))
            ->assertOk()
            ->assertSee('0 in stock')
            ->assertSee('Out of Stock')
            ->assertSee('sp-pill--rose', false);
    }

    public function test_the_empty_state_offers_a_reset_link_when_filters_are_applied(): void
    {
        $this->mine(['name' => 'Vanilla Candle']);

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index', ['q' => 'nothing-matches-this']))
            ->assertOk()
            ->assertSee('No products match your filters')
            ->assertSee('Reset filters')
            ->assertDontSee('Vanilla Candle');
    }

    // ── Add Product modal (hosted by the index page) ─────────────────────────

    public function test_the_products_page_hosts_the_add_product_modal_closed(): void
    {
        $this->mine();

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index'))
            ->assertOk()
            ->assertSee('id="productModalOverlay"', false)
            ->assertSee('role="dialog"', false)
            ->assertSee('aria-modal="true"', false)
            ->assertSee('data-auto-open="0"', false)
            ->assertSee('id="productModalFlag"', false)
            ->assertSee('value="product-create"', false);
    }

    public function test_the_modal_auto_opens_for_the_create_query_flag(): void
    {
        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index', ['create' => 1]))
            ->assertOk()
            ->assertSee('data-auto-open="1"', false);
    }

    public function test_the_create_url_redirects_to_the_modal_on_the_index(): void
    {
        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.create'))
            ->assertRedirect(route('seller.products.index', ['create' => 1]));
    }

    public function test_the_create_flag_is_not_carried_into_pagination_links(): void
    {
        Product::factory()->count(25)->create(['seller_id' => $this->seller->id]);

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index', ['create' => 1]))
            ->assertOk()
            ->assertViewHas('products', fn ($products) => ! str_contains((string) $products->nextPageUrl(), 'create'));
    }

    public function test_a_failed_store_flashes_the_modal_marker_and_errors(): void
    {
        $this->actingAs($this->seller, 'seller')
            ->from(route('seller.products.index'))
            ->post(route('seller.products.store'), [
                '_modal' => 'product-create',
                'name' => 'Half Filled Lantern',
                'price' => '',
                'stock_quantity' => '',
                'status' => 'draft',
            ])
            ->assertRedirect(route('seller.products.index'))
            ->assertSessionHasErrors(['price', 'stock_quantity', 'images'])
            ->assertSessionHas('_old_input._modal', 'product-create')
            ->assertSessionHas('_old_input.name', 'Half Filled Lantern');
    }

    public function test_the_modal_reopens_with_errors_and_old_input(): void
    {
        // Mirrors the JSON session payload the browser reloads into after a failed store() POST.
        $this->withSession([
            '_old_input' => ['_modal' => 'product-create', 'name' => 'Half Filled Lantern'],
            'errors' => ['default' => ['format' => ':message', 'messages' => ['price' => ['The price field is required.']]]],
        ])
            ->actingAs($this->seller, 'seller')
            ->get(route('seller.products.index'))
            ->assertOk()
            ->assertSee('data-auto-open="1"', false)
            ->assertSee('value="Half Filled Lantern"', false)
            ->assertSee('The price field is required.', false);
    }

    // ── Status toggle ────────────────────────────────────────────────────────

    public function test_toggle_status_sets_a_draft_product_active(): void
    {
        $product = $this->mine(['status' => 'draft']);

        $this->actingAs($this->seller, 'seller')
            ->patchJson(route('seller.products.toggleStatus', $product), ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('label', 'Active');

        $this->assertSame('active', $product->fresh()->status);
    }

    public function test_toggle_status_sets_a_product_inactive(): void
    {
        $product = $this->mine(['status' => 'active']);

        $this->actingAs($this->seller, 'seller')
            ->patchJson(route('seller.products.toggleStatus', $product), ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('status', 'inactive');

        $this->assertSame('inactive', $product->fresh()->status);
    }

    public function test_a_seller_cannot_toggle_another_sellers_product(): void
    {
        $foreign = Product::factory()->create(['seller_id' => $this->otherSeller->id]);

        $this->actingAs($this->seller, 'seller')
            ->patchJson(route('seller.products.toggleStatus', $foreign), ['status' => 'active'])
            ->assertForbidden();

        $this->assertSame('active', $foreign->fresh()->status);
    }

    public function test_toggle_status_rejects_unknown_statuses(): void
    {
        $product = $this->mine(['status' => 'draft']);

        $this->actingAs($this->seller, 'seller')
            ->patchJson(route('seller.products.toggleStatus', $product), ['status' => 'archived'])
            ->assertStatus(422);

        $this->assertSame('draft', $product->fresh()->status);
    }

    // ── Delete ───────────────────────────────────────────────────────────────

    public function test_delete_soft_deletes_the_sellers_own_product(): void
    {
        $product = $this->mine(['name' => 'Doomed Clock']);

        $this->actingAs($this->seller, 'seller')
            ->deleteJson(route('seller.products.destroy', $product))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('products', ['id' => $product->id]);
    }

    public function test_a_seller_cannot_delete_another_sellers_product(): void
    {
        $foreign = Product::factory()->create(['seller_id' => $this->otherSeller->id]);

        $this->actingAs($this->seller, 'seller')
            ->deleteJson(route('seller.products.destroy', $foreign))
            ->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $foreign->id, 'deleted_at' => null]);
    }

    public function test_guests_are_redirected_to_the_seller_login(): void
    {
        $this->get(route('seller.products.index'))
            ->assertRedirect(route('seller.login'));
    }
}
