<?php

namespace Tests\Feature\Seller;

use App\Http\Controllers\Buyer\CheckoutController;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Buyer\CheckoutToken;
use App\Models\Catalog\InventoryTransaction;
use App\Models\Catalog\Product;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use App\Models\Shared\Order;
use App\Services\Seller\SellerVacationService;
use App\Services\Seller\VariantSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class SellerVacationModeTest extends TestCase
{
    use BootstrapsCheckoutCarriers, LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function fixture(): array
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();
        $category = Category::create(['label' => 'Vacation test category', 'icon' => 'bag']);
        $product = Product::factory()->create(['seller_id' => $seller->id, 'category_id' => $category->id, 'name' => 'Rattan Fruit Basket', 'stock_quantity' => 5, 'price' => '100.00']);
        $variant = $product->variants()->sole();
        $line = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]);
        $this->bootstrapCheckoutCarriers($buyer, [$seller]);

        return [$buyer, $seller, $product, $variant, $line];
    }

    public function test_sel_prof_009_seller_can_enable_vacation_without_modifying_profile_products_or_inventory(): void
    {
        [$buyer, $seller, $product, $variant] = $this->fixture();
        $sellerBefore = $seller->fresh()->getRawOriginal();
        $productBefore = $product->fresh()->getRawOriginal();
        $variantBefore = $variant->fresh()->getRawOriginal();
        $this->actingAs($seller, 'seller')->get(route('seller.account.index'))->assertSee('Vacation mode')->assertSee('Save vacation mode');
        $this->patch(route('seller.account.vacation'), ['is_on_vacation' => '1'])->assertRedirect(route('seller.account.index'))->assertSessionHas('success');
        $this->assertTrue($seller->fresh()->is_on_vacation);
        $this->assertSame(Arr::except($sellerBefore, ['is_on_vacation', 'updated_at']), Arr::except($seller->fresh()->getRawOriginal(), ['is_on_vacation', 'updated_at']));
        $this->assertSame($productBefore, $product->fresh()->getRawOriginal());
        $this->assertSame($variantBefore, $variant->fresh()->getRawOriginal());
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_buy_srch_011_vacation_products_remain_in_search_platform_category_and_storefront(): void
    {
        [$buyer, $seller, $product] = $this->fixture();
        app(SellerVacationService::class)->set($seller, true);
        foreach ([route('buyer.search.index', ['q' => $product->name]), route('buyer.categories.show', $product->category_id), route('buyer.stores.show', $seller), route('buyer.products.index')] as $url) {
            $this->get($url)->assertOk()->assertSee($product->name)->assertSee('On vacation');
        }
        $response = $this->get(route('buyer.products.show', $product))->assertOk()->assertSee('On vacation');
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame(2, $xpath->query('//*[@id="pdp-add-cart" or @id="pdp-buy-now"]/@disabled')->length);
        $this->assertSame(2, $xpath->query('//*[@id="pdp-add-cart" or @id="pdp-buy-now"][@data-vacation="1"]')->length);
        $this->assertSame('active', $product->fresh()->status);
    }

    public function test_variant_selection_cannot_make_a_vacation_product_purchaseable(): void
    {
        [$buyer, $seller, $product, $default, $line] = $this->fixture();
        $line->delete();
        app(VariantSyncService::class)->sync($product, [['name' => 'Size', 'values' => ['S', 'M']]], [
            ['values' => ['S'], 'price' => '100.00', 'stock_quantity' => 3, 'weight_grams' => 500, 'is_available' => true],
            ['values' => ['M'], 'price' => '120.00', 'stock_quantity' => 3, 'weight_grams' => 500, 'is_available' => true],
        ], true);
        app(SellerVacationService::class)->set($seller, true);
        $response = $this->get(route('buyer.products.show', $product))->assertOk()->assertSee('data-vacation="1"', false);
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $this->assertSame(2, (new \DOMXPath($dom))->query('//*[@id="pdp-add-cart" or @id="pdp-buy-now"]/@disabled')->length);
        $this->actingAs($buyer, 'buyer');
        foreach ($product->variants()->get() as $variant) {
            $this->postJson(route('buyer.cart.add'), ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1])->assertUnprocessable()->assertJsonValidationErrors('product_id');
        }
    }

    public function test_sel_prof_009_direct_add_buy_now_and_cart_quantity_requests_are_rejected(): void
    {
        [$buyer, $seller, $product, $variant, $line] = $this->fixture();
        app(SellerVacationService::class)->set($seller, true);
        $this->actingAs($buyer, 'buyer');
        $data = ['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2];
        foreach (['buyer.cart.add', 'buyer.checkout.buy-now'] as $route) {
            $this->postJson(route($route), $data)->assertUnprocessable()->assertJsonValidationErrors('product_id')
                ->assertJsonPath('errors.product_id.0', Seller::VACATION_MESSAGE);
        }
        $this->patchJson(route('buyer.cart.update', $line), ['quantity' => 2])->assertUnprocessable();
        $this->assertSame(1, $line->fresh()->quantity);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertSame(5, $variant->fresh()->stock_quantity);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_sel_prof_009_existing_cart_checkout_is_blocked_with_a_vacation_message(): void
    {
        [$buyer, $seller, $product, $variant, $line] = $this->fixture();
        app(SellerVacationService::class)->set($seller, true);
        $this->actingAs($buyer, 'buyer')->get(route('buyer.cart.index'))->assertSee('On vacation');
        $this->get(route('buyer.checkout.index', ['items' => [$line->id]]))->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error', Seller::VACATION_MESSAGE);
        $this->get(route('buyer.checkout.index'))->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error', Seller::VACATION_MESSAGE);
        $token = CheckoutToken::issue($buyer->id);
        $this->withSession([CheckoutController::SESSION_KEY => [$line->id]])->post(route('buyer.checkout.store'), [
            'checkout_token' => $token->token, ...$this->checkoutCarrierPayload([$seller]),
        ])->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error', fn ($message) => str_contains($message, 'vacation'));
        $this->assertFalse($token->fresh()->used);
        foreach (['orders', 'seller_orders', 'order_items', 'payments', 'inventory_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(5, $variant->fresh()->stock_quantity);
        $this->assertModelExists($line);
    }

    public function test_sel_prof_010_disabling_vacation_restores_purchasing_and_cod_checkout_without_republishing(): void
    {
        [$buyer, $seller, $product, $variant, $line] = $this->fixture();
        $before = $product->fresh()->getRawOriginal();
        app(SellerVacationService::class)->set($seller, true);
        $this->actingAs($seller, 'seller')->patch(route('seller.account.vacation'), ['is_on_vacation' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse($seller->fresh()->is_on_vacation);
        $response = $this->get(route('buyer.products.show', $product))->assertDontSee('On vacation');
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $this->assertSame(0, (new \DOMXPath($dom))->query('//*[@id="pdp-add-cart" or @id="pdp-buy-now"]/@disabled')->length);
        $this->assertSame($before, $product->fresh()->getRawOriginal());
        $this->actingAs($buyer, 'buyer');
        $token = CheckoutToken::issue($buyer->id);
        $this->withSession([CheckoutController::SESSION_KEY => [$line->id]])->post(route('buyer.checkout.store'), [
            'checkout_token' => $token->token, ...$this->checkoutCarrierPayload([$seller]),
        ])->assertSessionMissing('error');
        $order = Order::query()->sole();
        $this->assertSame('cod', $order->sellerOrders()->sole()->payment->method->value);
        $this->assertSame(4, $variant->fresh()->stock_quantity);
        $this->assertSame(1, InventoryTransaction::query()->where('reason', 'checkout')->count());
        $this->assertModelMissing($line);
    }

    public function test_open_seller_can_be_checked_out_from_a_mixed_cart_without_touching_vacation_items(): void
    {
        [$buyer, $vacation, $product, $variant, $line] = $this->fixture();
        $open = Seller::factory()->create();
        $openProduct = Product::factory()->create(['seller_id' => $open->id, 'stock_quantity' => 5, 'price' => '200.00']);
        $openVariant = $openProduct->variants()->sole();
        $openLine = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $openProduct->id, 'variant_id' => $openVariant->id, 'quantity' => 1]);
        $this->bootstrapCheckoutCarriers($buyer, [$vacation, $open]);
        app(SellerVacationService::class)->set($vacation, true);
        $this->actingAs($buyer, 'buyer')->get(route('buyer.cart.index'))->assertViewHas('summary', fn ($summary) => $summary->subtotalCentavos === 20000 && $summary->unavailableItems->contains('id', $line->id));
        $this->get(route('buyer.checkout.index', ['items' => [$line->id, $openLine->id]]))->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error', Seller::VACATION_MESSAGE);
        $this->get(route('buyer.checkout.index', ['items' => [$openLine->id]]))->assertOk();
        $token = CheckoutToken::issue($buyer->id);
        $this->withSession([CheckoutController::SESSION_KEY => [$openLine->id]])->post(route('buyer.checkout.store'), [
            'checkout_token' => $token->token, ...$this->checkoutCarrierPayload([$open]),
        ])->assertSessionMissing('error');
        $this->assertSame([$open->id], Order::query()->sole()->sellerOrders()->pluck('seller_id')->all());
        $this->assertSame(5, $variant->fresh()->stock_quantity);
        $this->assertSame(4, $openVariant->fresh()->stock_quantity);
        $this->assertModelExists($line);
        $this->assertModelMissing($openLine);
    }

    public function test_stale_mixed_checkout_is_rejected_atomically_instead_of_silently_creating_a_partial_order(): void
    {
        [$buyer, $vacation, $product, $variant, $line] = $this->fixture();
        $open = Seller::factory()->create();
        $other = Product::factory()->create(['seller_id' => $open->id, 'stock_quantity' => 5]);
        $openLine = CartItem::factory()->create(['buyer_id' => $buyer->id, 'product_id' => $other->id, 'variant_id' => $other->variants()->sole()->id, 'quantity' => 1]);
        $this->bootstrapCheckoutCarriers($buyer, [$vacation, $open]);
        app(SellerVacationService::class)->set($vacation, true);
        $token = CheckoutToken::issue($buyer->id);
        $this->actingAs($buyer, 'buyer')->withSession([CheckoutController::SESSION_KEY => [$line->id, $openLine->id]])->post(route('buyer.checkout.store'), [
            'checkout_token' => $token->token, ...$this->checkoutCarrierPayload([$vacation, $open]),
        ])->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error', fn ($message) => str_contains($message, 'vacation'));
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertSame(5, $variant->fresh()->stock_quantity);
        $this->assertSame(5, $other->fresh()->stock_quantity);
        $this->assertDatabaseCount('cart_items', 2);
    }

    public function test_transaction_recheck_does_not_trust_a_seller_snapshot_loaded_before_vacation_was_enabled(): void
    {
        [$buyer, $seller, $product, $variant, $line] = $this->fixture();
        $token = CheckoutToken::issue($buyer->id);
        $changed = false;
        Seller::retrieved(function ($row) use ($seller, &$changed): void {
            if (! $changed && $row->id === $seller->id) {
                $changed = true;
                DB::table('sellers')->where('id', $row->id)->update(['is_on_vacation' => true]);
            }
        });
        $this->actingAs($buyer, 'buyer')->withSession([CheckoutController::SESSION_KEY => [$line->id]])->post(route('buyer.checkout.store'), [
            'checkout_token' => $token->token, ...$this->checkoutCarrierPayload([$seller]),
        ])->assertRedirect(route('buyer.cart.index'))->assertSessionHas('error', Seller::VACATION_MESSAGE);
        $this->assertTrue($changed);
        $this->assertFalse($token->fresh()->used);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertSame(5, $variant->fresh()->stock_quantity);
    }

    public static function invalidValues(): array
    {
        return [[null], ['enabled'], [[true]], [2]];
    }

    #[DataProvider('invalidValues')]
    public function test_vacation_setting_requires_a_valid_boolean(mixed $value): void
    {
        $seller = Seller::factory()->create();
        $this->actingAs($seller, 'seller')->patchJson(route('seller.account.vacation'), ['is_on_vacation' => $value])->assertUnprocessable()->assertJsonValidationErrors('is_on_vacation');
        $this->assertFalse($seller->fresh()->is_on_vacation);
    }

    public function test_a_seller_cannot_toggle_another_sellers_vacation_status(): void
    {
        $seller = Seller::factory()->create();
        $other = Seller::factory()->create();
        foreach (['id', 'seller_id', 'store_id'] as $field) {
            $this->actingAs($seller, 'seller')->patchJson(route('seller.account.vacation'), ['is_on_vacation' => true, $field => $other->id])->assertForbidden();
        }
        $this->assertFalse($seller->fresh()->is_on_vacation);
        $this->assertFalse($other->fresh()->is_on_vacation);
    }

    public static function otherRoles(): array
    {
        return [['buyer', Buyer::class], ['admin', Admin::class], ['logistics', LogisticsProvider::class], ['rider', Rider::class]];
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_cannot_toggle_seller_vacation(string $guard, string $model): void
    {
        $seller = Seller::factory()->create();
        $this->actingAs($model::factory()->create(), $guard)->patchJson(route('seller.account.vacation'), ['is_on_vacation' => true])->assertUnauthorized();
        $this->assertFalse($seller->fresh()->is_on_vacation);
    }

    public function test_guests_and_unapproved_sellers_cannot_toggle_vacation(): void
    {
        $this->patchJson(route('seller.account.vacation'), ['is_on_vacation' => true])->assertUnauthorized();
        $seller = Seller::factory()->pending()->create();
        $this->actingAs($seller, 'seller')->patch(route('seller.account.vacation'), ['is_on_vacation' => true])->assertRedirect();
        $this->assertFalse($seller->fresh()->is_on_vacation);
    }

    public function test_turning_vacation_off_does_not_publish_a_draft_or_bypass_seller_suspension(): void
    {
        [$buyer, $seller, $product] = $this->fixture();
        $product->forceFill(['status' => 'draft'])->save();
        app(SellerVacationService::class)->set($seller, true);
        app(SellerVacationService::class)->set($seller, false);
        $this->get(route('buyer.products.show', $product))->assertNotFound();
        $this->assertSame('draft', $product->fresh()->status);
        $seller->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($seller, 'seller')->patch(route('seller.account.vacation'), ['is_on_vacation' => false])->assertRedirect();
        $this->assertSame('suspended', $seller->fresh()->status);
    }
}
