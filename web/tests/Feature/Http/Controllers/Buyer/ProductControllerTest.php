<?php

namespace Tests\Feature\Http\Controllers\Buyer;

use App\Models\Product;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_product_page_renders_description(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
            'description' => "Line one\nLine two <script>alert(1)</script>",
        ]);

        $this->get(route('buyer.products.show', $product->id))
            ->assertOk()
            ->assertSee('Line one', false)
            ->assertSee('Line two', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
