<?php

namespace Tests\Feature\Http\Controllers\Buyer;

use App\Models\Product;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class HomeControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_can_view_the_buyer_home_page_layout(): void
    {
        $response = $this->get(route('buyer.home'));

        $response->assertOk();
        $response->assertSee('Limited Time Offer');
        $response->assertSee('Flash Sale');
        $response->assertSee('Todays For You!');
        $response->assertSee('Best Selling Store');
        $response->assertSee('Product coming soon');
    }

    public function test_home_lists_active_in_stock_products_and_hides_unavailable_ones(): void
    {
        $seller = Seller::factory()->create(['business_name' => 'Hearth Nest']);

        $visible = Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => 'Linen Blend Throw Pillow',
            'price' => 76.00,
            'status' => 'active',
            'stock_quantity' => 12,
        ]);

        Product::factory()->draft()->create([
            'seller_id' => $seller->id,
            'name' => 'Hidden Draft Coat',
        ]);

        Product::factory()->outOfStock()->create([
            'seller_id' => $seller->id,
            'name' => 'Sold Out Camera Bag',
            'status' => 'active',
        ]);

        $response = $this->get(route('buyer.home'));

        $response->assertOk();
        $response->assertSee('Linen Blend Throw Pillow');
        $response->assertSee('₱76.00');
        $response->assertDontSee('Hidden Draft Coat');
        $response->assertDontSee('Sold Out Camera Bag');
        $response->assertSee('Hearth Nest');
        $this->assertModelExists($visible);
    }

    public function test_home_escapes_product_and_store_names_in_html(): void
    {
        $seller = Seller::factory()->create([
            'business_name' => "Nest <script>alert('store')</script>",
        ]);

        Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => "Pillow <script>alert('xss')</script>",
        ]);

        $response = $this->get(route('buyer.home'));

        $response->assertOk();
        $this->assertStringContainsString('&lt;script&gt;', $response->getContent());
        $this->assertStringNotContainsString("<script>alert('xss')</script>", $response->getContent());
        $this->assertStringNotContainsString("<script>alert('store')</script>", $response->getContent());
    }

    public function test_home_omits_unapproved_sellers_from_best_selling_stores(): void
    {
        $pending = Seller::factory()->pendingApproval()->create([
            'business_name' => 'Pending Boutique',
        ]);

        Product::factory()->create([
            'seller_id' => $pending->id,
            'name' => 'Pending Only Product',
        ]);

        $response = $this->get(route('buyer.home'));

        $response->assertOk();
        $response->assertDontSee('Pending Boutique');
        $response->assertDontSee('Pending Only Product');
    }
}
