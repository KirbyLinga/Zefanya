<?php

namespace Tests\Feature\Seller;

use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Services\Seller\ProductPublishingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\ValidProductPublication;
use Tests\TestCase;

/** SEL-PROF-011: awaiting approval means draft-only at the publishing boundary. */
class UnapprovedSellerDraftTest extends TestCase
{
    use LazilyRefreshDatabase;
    use ValidProductPublication;

    private function payload(string $status): array
    {
        return [...$this->publicationDefaults(), 'name' => 'Handwoven Cotton Shopping Bag',
            'price' => 150, 'weight_grams' => 200, 'stock_quantity' => 3,
            'status' => $status, 'variants_exist' => false];
    }

    public static function nonDraftStatuses(): array
    {
        return [['active'], ['inactive']];
    }

    public function test_pending_seller_can_save_a_draft_through_the_service(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->pending()->create();
        $product = app(ProductPublishingService::class)->save(new Product, $seller, $this->payload('draft'));
        $this->assertSame('draft', $product->status);
        $this->assertSame($seller->id, $product->seller_id);
        $this->assertDatabaseCount('products', 1);
    }

    #[DataProvider('nonDraftStatuses')]
    public function test_pending_seller_cannot_create_a_non_draft_listing(string $status): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->pending()->create();
        try {
            app(ProductPublishingService::class)->save(new Product, $seller, $this->payload($status));
            $this->fail('An unapproved store saved a non-draft listing.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->assertDatabaseCount('products', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[DataProvider('nonDraftStatuses')]
    public function test_status_change_checks_current_approval_instead_of_a_stale_seller(string $status): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $stale = $seller->fresh();
        $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => 'draft']);
        $seller->forceFill(['status' => 'pending'])->save();
        try {
            app(ProductPublishingService::class)->setStatus($product, $stale, $status);
            $this->fail('A stale seller snapshot bypassed approval.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->assertSame('draft', $product->fresh()->status);
    }

    public function test_save_checks_current_approval_instead_of_a_stale_seller(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create(['status' => 'approved']);
        $stale = $seller->fresh();
        $seller->forceFill(['status' => 'pending'])->save();
        try {
            app(ProductPublishingService::class)->save(new Product, $stale, $this->payload('active'));
            $this->fail('A stale seller snapshot bypassed approval.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->assertDatabaseCount('products', 0);
    }

    public function test_approved_seller_can_publish_a_complete_listing(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create(['status' => 'approved']);
        $product = app(ProductPublishingService::class)->save(new Product, $seller, $this->payload('active'));
        $this->assertSame('active', $product->status);
    }

    public function test_pending_seller_still_cannot_use_the_product_pages_or_submit_a_draft(): void
    {
        $seller = Seller::factory()->pending()->create();
        $this->actingAs($seller, 'seller')->get(route('seller.products.index'))->assertForbidden()->assertSee('under review');
        $this->postJson(route('seller.products.store'), ['status' => 'draft'])->assertForbidden();
        $this->assertAuthenticatedAs($seller, 'seller');
        $this->assertDatabaseCount('products', 0);
    }
}
