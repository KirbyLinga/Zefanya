<?php

namespace Tests\Feature\Admin;

use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Admin\Admin;
use App\Models\Admin\AdminProductRestriction;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Shared\Category;
use App\Services\Seller\ProductPublishingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ValidProductPublication;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class AdminProductOversightTest extends TestCase
{
    use BootstrapsCheckoutCarriers, LazilyRefreshDatabase, ValidProductPublication;

    private function restrict(Product $product): void
    {
        $product->forceFill(['restriction_reason' => 'Unsafe listing', 'restricted_at' => now(), 'restricted_by' => Admin::factory()->create()->id])->save();
    }

    public static function allowedRoles(): array
    {
        return [['super_admin'], ['admin'], ['moderator']];
    }

    #[DataProvider('allowedRoles')]
    public function test_authorized_admins_restrict_and_restore_without_changing_seller_status(string $role): void
    {
        $this->freezeTime();
        $admin = Admin::factory()->create(['role' => $role]);
        $product = Product::factory()->create(['status' => 'inactive']);
        $this->actingAs($admin, 'admin')->get(route('admin.products.index'))->assertOk();
        $this->get(route('admin.products.show', $product))->assertOk()->assertSee($product->name)->assertSee('Not restricted');

        $this->post(route('admin.products.restrict', $product), ['reason' => 'Unsafe listing'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'inactive', 'restriction_reason' => 'Unsafe listing', 'restricted_by' => $admin->id, 'restricted_at' => now()]);
        $this->assertDatabaseHas('admin_product_restrictions', ['product_id' => $product->id, 'admin_id' => $admin->id, 'admin_name' => $admin->name, 'admin_email' => $admin->email, 'product_name' => $product->name, 'seller_name' => $product->seller->business_name, 'reason' => 'Unsafe listing', 'previous_status' => 'inactive', 'new_status' => 'inactive']);

        $this->post(route('admin.products.unrestrict', $product), ['reason' => 'Reviewed'])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'inactive', 'restriction_reason' => null, 'restricted_at' => null, 'restricted_by' => null]);
        $this->assertDatabaseHas('admin_product_restrictions', ['product_id' => $product->id, 'reason' => 'Reviewed', 'admin_id' => $admin->id]);
        $this->assertDatabaseCount('admin_product_restrictions', 2);
    }

    public static function unauthorizedActors(): array
    {
        return [['guest'], ['buyer'], ['seller'], ['support']];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_other_actors_cannot_access_admin_oversight(string $actor): void
    {
        $product = Product::factory()->create();
        if ($actor !== 'guest') {
            $user = match ($actor) {
                'buyer' => Buyer::factory()->create(),
                'seller' => $product->seller,
                'support' => Admin::factory()->create(['role' => 'support']),
            };
            $this->actingAs($user, $actor === 'support' ? 'admin' : $actor);
        }
        foreach ([['GET', 'index'], ['GET', 'show'], ['POST', 'restrict'], ['POST', 'unrestrict']] as [$method, $action]) {
            $response = $this->json($method, route('admin.products.'.$action, $action === 'index' ? [] : $product), ['reason' => 'Forged']);
            $actor === 'support' ? $response->assertForbidden() : $response->assertUnauthorized();
        }
        $this->assertNull($product->fresh()->restriction_reason);
        $this->assertDatabaseCount('admin_product_restrictions', 0);
    }

    public static function invalidReasons(): array
    {
        return ['missing' => [[]], 'empty' => [['reason' => ' ']], 'wrong type' => [['reason' => ['invalid']]], 'too long' => [['reason' => str_repeat('a', 1001)]]];
    }

    #[DataProvider('invalidReasons')]
    public function test_invalid_restriction_reason_changes_nothing(array $payload): void
    {
        $product = Product::factory()->create();
        $this->actingAs(Admin::factory()->create(), 'admin')->postJson(route('admin.products.restrict', $product), $payload)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertNull($product->fresh()->restriction_reason);
        $this->assertDatabaseCount('admin_product_restrictions', 0);
    }

    public static function optionalReasons(): array
    {
        return ['omitted' => [[]], 'null' => [['reason' => null]], 'blank' => [['reason' => '']]];
    }

    #[DataProvider('optionalReasons')]
    public function test_unrestrict_accepts_optional_reason_and_records_a_default(array $payload): void
    {
        $product = Product::factory()->create();
        $this->restrict($product);
        $this->actingAs(Admin::factory()->create(), 'admin')->post(route('admin.products.unrestrict', $product), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->restriction_reason);
        $this->assertSame('Unrestricted by admin.', AdminProductRestriction::query()->sole()->reason);
    }

    public function test_unrestricting_an_unrestricted_product_does_not_write_history(): void
    {
        $product = Product::factory()->create();
        $this->actingAs(Admin::factory()->create(), 'admin')->post(route('admin.products.unrestrict', $product))->assertSessionHas('error');
        $this->assertDatabaseCount('admin_product_restrictions', 0);
    }

    public static function sellerActions(): array
    {
        return [['PUT', 'update'], ['PATCH', 'toggleStatus'], ['DELETE', 'destroy'], ['POST', 'duplicate'], ['DELETE', 'images.destroy']];
    }

    #[DataProvider('sellerActions')]
    public function test_seller_cannot_modify_a_restricted_product(string $method, string $action): void
    {
        Storage::fake('public');
        $product = Product::factory()->create();
        $image = $product->images()->create(['path' => 'restricted.png', 'is_primary' => true, 'sort_order' => 0]);
        $this->restrict($product);
        $before = $product->fresh()->getAttributes();
        $this->actingAs($product->seller, 'seller');
        $payload = array_replace($this->publicationDefaults(), ['name' => 'Changed product', 'price' => 100, 'stock_quantity' => 10, 'weight' => 500, 'weight_unit' => 'g', 'status' => 'active', 'restriction_reason' => null, 'restricted_by' => null, 'restricted_at' => null]);
        $payload['inventory_revision'] = app(ProductPublishingService::class)->inventoryRevision($product->fresh()->load('variants'));
        $product->images()->create(['path' => 'second.png', 'is_primary' => false, 'sort_order' => 1]);
        $parameters = $action === 'images.destroy' ? [$product, $image] : $product;

        $this->json($method, route('seller.products.'.$action, $parameters), $payload)->assertForbidden();

        $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertModelExists($image);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public static function bulkActions(): array
    {
        return [['activate'], ['inactivate'], ['archive']];
    }

    #[DataProvider('bulkActions')]
    public function test_bulk_actions_skip_restricted_products(string $action): void
    {
        $product = Product::factory()->create();
        $this->restrict($product);
        $before = $product->fresh()->getAttributes();
        $this->actingAs($product->seller, 'seller')->postJson(route('seller.products.bulk'), ['product_ids' => [$product->id], 'action' => $action])->assertOk()->assertJsonPath('skipped.0', $product->name.' (admin-restricted)');
        $this->assertSame($before, $product->fresh()->getAttributes());
    }

    public function test_restricted_products_are_hidden_from_buyer_discovery_and_details(): void
    {
        $category = Category::create(['label' => 'Oversight', 'icon' => 'shirt']);
        $product = Product::factory()->create(['name' => 'Oversight Restricted', 'category_id' => $category->id]);
        $visible = Product::factory()->create(['name' => 'Oversight Visible', 'category_id' => $category->id]);
        $this->restrict($product);
        foreach ([route('buyer.products.index'), route('buyer.search.index', ['q' => 'Oversight']), route('buyer.categories.show', $category)] as $url) {
            $this->get($url)->assertOk()->assertViewHas('products', fn ($products) => $products->modelKeys() === [$visible->id]);
        }
        $this->getJson(route('buyer.search.suggestions', ['term' => 'Oversight']))->assertOk()->assertJsonCount(1, 'popular');
        $this->get(route('buyer.products.show', $product))->assertNotFound();
    }

    public static function purchaseActions(): array
    {
        return [['cart.add'], ['checkout.buy-now']];
    }

    #[DataProvider('purchaseActions')]
    public function test_buyer_cannot_add_or_buy_a_restricted_product(string $action): void
    {
        $product = Product::factory()->create();
        $this->restrict($product);
        $this->actingAs(Buyer::factory()->create(), 'buyer')->postJson(route('buyer.'.$action), ['product_id' => $product->id, 'quantity' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('cart_items', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_restriction_after_checkout_preparation_blocks_submission_without_stock_or_payment_changes(): void
    {
        $buyer = Buyer::factory()->create();
        $product = Product::factory()->create(['stock_quantity' => 10]);
        $this->bootstrapCheckoutCarriers($buyer, [$product->seller]);
        $variant = $product->variants()->sole();
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]);
        $this->actingAs($buyer, 'buyer')->get(route('buyer.checkout.index'))->assertOk();
        $token = session(CheckoutController::TOKEN_KEY);
        $this->restrict($product);

        $this->patchJson(route('buyer.cart.update', $line), ['quantity' => 2])->assertUnprocessable();
        $this->post(route('buyer.checkout.store'), ['checkout_token' => $token, ...$this->checkoutCarrierPayload(collect([$product->seller]))])->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error');

        $this->assertSame(1, $line->fresh()->quantity);
        $this->assertSame(10, $variant->fresh()->stock_quantity);
        foreach (['orders', 'payments', 'inventory_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }
}
