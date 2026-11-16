<?php

namespace Tests\Feature\Seller;

use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/** Seller › Products › ⋮ › Purchase limits: per-product settings. */
class ProductPurchaseLimitSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'enabled' => true,
            'exempt_trusted' => true,
            'max_quantity' => '3',
            'max_order' => '₱1,500.50',
            'max_active' => '5000',
        ], $overrides);
    }

    private function setup2(): array
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => 'active']);

        return [$seller, $product];
    }

    public function test_products_page_offers_the_menu_item_and_dialog(): void
    {
        [$seller, $product] = $this->setup2();

        $this->actingAs($seller, 'seller')->get(route('seller.products.index'))
            ->assertOk()
            ->assertSee('data-purchase-limits-open="'.$product->id.'"', false)
            ->assertSee(route('seller.products.purchase-limits', $product, absolute: false), false)
            ->assertSee('id="plDialog"', false)
            ->assertSee('role="switch"', false);
    }

    public function test_saving_converts_pesos_to_centavos_and_can_be_cleared(): void
    {
        [$seller, $product] = $this->setup2();

        $this->actingAs($seller, 'seller')->patchJson(route('seller.products.purchase-limits', $product), $this->payload())
            ->assertOk()
            ->assertJsonPath('active', true)
            ->assertJsonPath('limits.max_order', '1500.5')
            ->assertJsonPath('limits.max_quantity', '3');

        $product->refresh();
        $this->assertTrue($product->purchase_limit_enabled);
        $this->assertTrue($product->purchase_limit_exempt_trusted);
        $this->assertSame(3, $product->purchase_limit_max_quantity);
        $this->assertSame(150050, $product->purchase_limit_max_order_minor);
        $this->assertSame(500000, $product->purchase_limit_max_active_minor);

        $this->patchJson(route('seller.products.purchase-limits', $product),
            $this->payload(['enabled' => false, 'exempt_trusted' => false, 'max_quantity' => '', 'max_order' => '', 'max_active' => '']))
            ->assertOk()->assertJsonPath('active', false);

        $product->refresh();
        $this->assertFalse($product->purchase_limit_enabled);
        $this->assertNull($product->purchase_limit_max_quantity);
        $this->assertNull($product->purchase_limit_max_order_minor);
        $this->assertNull($product->purchase_limit_max_active_minor);
    }

    public function test_only_the_chosen_product_changes(): void
    {
        [$seller, $product] = $this->setup2();
        $sibling = Product::factory()->create(['seller_id' => $seller->id, 'status' => 'active']);

        $this->actingAs($seller, 'seller')->patchJson(route('seller.products.purchase-limits', $product), $this->payload())->assertOk();

        $this->assertFalse($sibling->fresh()->purchase_limit_enabled);
        $this->assertNull($sibling->fresh()->purchase_limit_max_quantity);
    }

    public function test_validation(): void
    {
        [$seller, $product] = $this->setup2();
        $this->actingAs($seller, 'seller');

        foreach ([
            [['max_quantity' => '0'], 'max_quantity'],
            [['max_quantity' => '1000'], 'max_quantity'],
            [['max_quantity' => '1.5'], 'max_quantity'],
            [['max_order' => '0'], 'max_order'],
            [['max_order' => '-5'], 'max_order'],
            [['max_order' => '10.555'], 'max_order'],
            [['max_order' => 'abc'], 'max_order'],
            [['max_active' => '50000001'], 'max_active'],
            [['max_order' => '600', 'max_active' => '500'], 'max_active'],
            [['max_quantity' => '', 'max_order' => '', 'max_active' => ''], 'enabled'],
            [['enabled' => 'yes'], 'enabled'],
        ] as [$override, $field]) {
            $this->patchJson(route('seller.products.purchase-limits', $product), $this->payload($override))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->assertFalse($product->fresh()->purchase_limit_enabled);
    }

    public function test_another_sellers_product_cannot_be_changed(): void
    {
        [$seller] = $this->setup2();
        [, $foreign] = $this->setup2();

        $this->actingAs($seller, 'seller')->patchJson(route('seller.products.purchase-limits', $foreign), $this->payload())->assertForbidden();

        $this->assertFalse($foreign->fresh()->purchase_limit_enabled);
    }

    public function test_guests_buyers_unapproved_sellers_and_restricted_products_are_refused(): void
    {
        [$seller, $product] = $this->setup2();

        $this->patchJson(route('seller.products.purchase-limits', $product), $this->payload())->assertUnauthorized();
        $this->actingAs(Buyer::factory()->create(), 'buyer')->patchJson(route('seller.products.purchase-limits', $product), $this->payload())->assertUnauthorized();

        $pending = Seller::factory()->create(['status' => 'pending']);
        $pendingProduct = Product::factory()->create(['seller_id' => $pending->id]);
        $this->actingAs($pending, 'seller')->patchJson(route('seller.products.purchase-limits', $pendingProduct), $this->payload())->assertForbidden();
        $this->assertFalse($pendingProduct->fresh()->purchase_limit_enabled);

        $product->forceFill(['restricted_at' => now(), 'restriction_reason' => 'Policy review'])->save();
        $this->actingAs($seller, 'seller')->patchJson(route('seller.products.purchase-limits', $product), $this->payload())->assertForbidden();
        $this->assertFalse($product->fresh()->purchase_limit_enabled);
    }
}
