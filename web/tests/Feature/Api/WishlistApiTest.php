<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use App\Models\Product;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class WishlistApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createBuyer(): Buyer
    {
        return Buyer::factory()->create();
    }

    private function createProduct(): Product
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        return Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
        ]);
    }

    public function test_buyer_can_add_list_and_remove_wishlist_items(): void
    {
        $buyer = $this->createBuyer();
        $token = $buyer->createToken('test')->plainTextToken;
        $product = $this->createProduct();

        // 1. Add to wishlist
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson(route('api.buyer.wishlist.store', $product));

        $response->assertStatus(201)
            ->assertJson([
                'ok' => true,
                'product_id' => (string) $product->id,
            ]);

        // 2. List wishlist
        $listResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.buyer.wishlist.index'));

        $listResponse->assertOk()
            ->assertJson([
                'ok' => true,
                'product_ids' => [(string) $product->id],
            ])
            ->assertJsonCount(1, 'products');

        // 3. Remove from wishlist
        $deleteResponse = $this->withHeader('Authorization', "Bearer {$token}")
            ->deleteJson(route('api.buyer.wishlist.destroy', $product));

        $deleteResponse->assertOk()
            ->assertJson([
                'ok' => true,
                'product_id' => (string) $product->id,
            ]);

        // 4. Verify empty list
        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson(route('api.buyer.wishlist.index'))
            ->assertOk()
            ->assertJsonCount(0, 'products');
    }

    public function test_adding_same_product_to_wishlist_is_idempotent(): void
    {
        $buyer = $this->createBuyer();
        $token = $buyer->createToken('test')->plainTextToken;
        $product = $this->createProduct();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson(route('api.buyer.wishlist.store', $product))
            ->assertStatus(201);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson(route('api.buyer.wishlist.store', $product))
            ->assertStatus(201);

        $this->assertDatabaseCount('wishlist_items', 1);
    }
}
