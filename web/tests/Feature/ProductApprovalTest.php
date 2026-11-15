<?php

namespace Tests\Feature;

use App\Models\Admin\Admin;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductApprovalEvent;
use App\Models\Catalog\ProductRevision;
use App\Models\Seller\Seller;
use App\Notifications\Admin\ProductSubmitted;
use App\Notifications\Seller\ProductApprovalDecision;
use App\Services\Seller\ProductPublishingService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ValidProductPublication;
use Tests\TestCase;

class ProductApprovalTest extends TestCase
{
    use LazilyRefreshDatabase;
    use ValidProductPublication;

    private function payload(array $overrides = []): array
    {
        return [
            ...$this->publicationDefaults(),
            'name' => 'Handwoven Cotton Market Bag',
            'price' => 150,
            'weight' => 200,
            'weight_unit' => 'g',
            'weight_grams' => 200,
            'stock_quantity' => 4,
            'status' => 'active',
            'variants_exist' => false,
            ...$overrides,
        ];
    }

    public function test_a_new_product_waits_for_admin_approval_and_stays_out_of_the_catalog(): void
    {
        Storage::fake('public');
        Notification::fake();
        $seller = Seller::factory()->create();
        $admin = Admin::factory()->create(['role' => 'admin']);

        $this->actingAs($seller, 'seller')->post(route('seller.products.store'), $this->payload())
            ->assertRedirect(route('seller.products.index'))
            ->assertSessionHas('success');

        $product = Product::query()->sole();
        $this->assertSame('pending', $product->status);
        $this->assertNull($product->approved_at);
        $this->assertFalse($product->isListedForSale());

        Notification::assertSentTo($admin, ProductSubmitted::class);
        $this->actingAs($admin, 'admin')->get(route('admin.products.pending'))
            ->assertOk()
            ->assertSee($product->name)
            ->assertSee('Approve');
        $this->get(route('buyer.products.show', $product))->assertNotFound();
        $this->getJson('/api/buyer/products')->assertOk()->assertJsonMissing(['id' => $product->id]);
        $this->get(route('buyer.products.index', ['q' => 'Handwoven Cotton']))->assertOk()->assertDontSee($product->name);
    }

    public function test_approval_publishes_the_product_and_records_the_admin(): void
    {
        Storage::fake('public');
        Notification::fake();
        $seller = Seller::factory()->create();
        $admin = Admin::factory()->create(['role' => 'moderator']);
        $product = app(ProductPublishingService::class)->save(new Product, $seller, $this->payload());

        $this->actingAs($admin, 'admin')
            ->post(route('admin.products.approve', $product))
            ->assertRedirect()
            ->assertSessionHas('success');

        $product->refresh();
        $this->assertSame('active', $product->status);
        $this->assertNotNull($product->approved_at);
        $this->assertTrue($product->isListedForSale());
        $this->assertDatabaseHas('product_approval_events', [
            'product_id' => $product->id,
            'admin_id' => $admin->id,
            'decision' => 'approved',
        ]);
        Notification::assertSentTo($seller, ProductApprovalDecision::class);
        $this->get(route('buyer.products.show', $product))->assertOk();
        $this->post(route('admin.products.approve', $product))->assertSessionHasErrors('product');
        $this->assertSame(1, ProductApprovalEvent::query()->where('product_id', $product->id)->where('decision', 'approved')->count());
    }

    public function test_rejection_requires_a_reason_and_a_resubmission_returns_to_the_queue(): void
    {
        Storage::fake('public');
        Notification::fake();
        $seller = Seller::factory()->create();
        $admin = Admin::factory()->create();
        $product = app(ProductPublishingService::class)->save(new Product, $seller, $this->payload());

        $this->actingAs($admin, 'admin')
            ->post(route('admin.products.reject', $product), [])
            ->assertSessionHasErrors('reason');
        $this->assertSame('pending', $product->fresh()->status);

        $this->post(route('admin.products.reject', $product), ['reason' => 'The photos do not show the item.'])
            ->assertRedirect()
            ->assertSessionHas('success');
        $product->refresh();
        $this->assertSame('rejected', $product->status);
        $this->assertSame('The photos do not show the item.', $product->rejection_reason);
        $this->assertFalse($product->isListedForSale());
        Notification::assertSentTo($seller, ProductApprovalDecision::class);

        $this->actingAs($seller, 'seller')->get(route('seller.products.index'))
            ->assertOk()
            ->assertSee('Rejected')
            ->assertSee('The photos do not show the item.');

        $update = $this->payload([
            'name' => 'Handwoven Cotton Market Bag',
            'inventory_revision' => app(ProductPublishingService::class)->inventoryRevision($product->load('variants')),
        ]);
        unset($update['creation_key']);
        $this->put(route('seller.products.update', $product), $update)->assertRedirect();
        $this->assertSame('pending', $product->fresh()->status);
        $this->assertNull($product->fresh()->rejection_reason);
        $this->getJson('/api/buyer/products/'.$product->id)->assertNotFound();
    }

    public function test_a_seller_cannot_publish_directly_or_approve_their_own_product(): void
    {
        Storage::fake('public');
        $seller = Seller::factory()->create();
        $product = app(ProductPublishingService::class)->save(new Product, $seller, $this->payload());

        $this->actingAs($seller, 'seller')
            ->patch(route('seller.products.toggleStatus', $product), ['status' => 'active'])
            ->assertRedirect();
        $this->assertSame('pending', $product->fresh()->status);

        $this->post(route('seller.products.bulk'), [
            'product_ids' => [$product->id],
            'action' => 'activate',
        ])->assertRedirect();
        $this->assertSame('pending', $product->fresh()->status);
        $this->post(route('admin.products.approve', $product))->assertRedirect(route('admin.login'));
    }

    public function test_an_approved_listing_keeps_its_public_version_until_the_edit_is_approved(): void
    {
        Storage::fake('public');
        Notification::fake();
        $seller = Seller::factory()->create();
        $admin = Admin::factory()->create();
        $service = app(ProductPublishingService::class);
        $product = $service->save(new Product, $seller, $this->payload());
        $product->forceFill(['status' => 'active', 'approved_at' => now()])->save();
        $original = $product->name;

        $update = $this->payload([
            'name' => 'Revised Handwoven Market Bag',
            'price' => 240,
            'inventory_revision' => $service->inventoryRevision($product->load('variants')),
        ]);
        unset($update['creation_key']);

        $this->actingAs($seller, 'seller')
            ->put(route('seller.products.update', $product), $update)
            ->assertRedirect()
            ->assertSessionHas('success');

        $product->refresh();
        $this->assertSame($original, $product->name);
        $this->assertSame('150.00', $product->price);
        $this->assertSame('active', $product->status);
        $revision = ProductRevision::query()->where('product_id', $product->id)->where('status', 'pending')->first();
        $this->assertNotNull($revision);
        $this->assertSame('Revised Handwoven Market Bag', $revision->payload['name']);
        $this->get(route('buyer.products.show', $product))->assertOk()->assertSee($original)->assertDontSee('Revised Handwoven Market Bag');

        $this->put(route('seller.products.update', $product), [
            ...$update,
            'inventory_revision' => $service->inventoryRevision($product->fresh()->load('variants')),
            'name' => 'Second Revision Market Bag',
        ])->assertRedirect();
        $this->assertSame(1, ProductRevision::query()->where('product_id', $product->id)->where('status', 'pending')->count());

        $this->actingAs($admin, 'admin')->post(route('admin.products.approve', $product))->assertRedirect();
        $this->assertSame('Second Revision Market Bag', $product->fresh()->name);
        $this->assertSame('active', $product->fresh()->status);
        $this->assertDatabaseHas('product_approval_events', [
            'product_id' => $product->id,
            'admin_id' => $admin->id,
            'decision' => 'approved',
        ]);
    }

    public function test_rejecting_an_edit_leaves_the_live_product_and_notifies_the_seller(): void
    {
        Storage::fake('public');
        Notification::fake();
        $seller = Seller::factory()->create();
        $admin = Admin::factory()->create();
        $service = app(ProductPublishingService::class);
        $product = $service->save(new Product, $seller, $this->payload());
        $product->forceFill(['status' => 'active', 'approved_at' => now()])->save();
        $update = $this->payload([
            'description' => 'A completely different description of this bag for review.',
            'inventory_revision' => $service->inventoryRevision($product->load('variants')),
        ]);
        unset($update['creation_key']);
        $service->save($product, $seller, $update);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.products.reject', $product), ['reason' => 'Describe the materials more clearly.'])
            ->assertRedirect();

        $product->refresh();
        $this->assertSame('Handcrafted product with carefully finished details.', $product->description);
        $this->assertSame('active', $product->status);
        $this->assertTrue($product->isListedForSale());
        $this->assertSame('rejected', $product->latestRevision->status);
        $this->assertDatabaseHas('product_approval_events', [
            'product_id' => $product->id,
            'decision' => 'rejected',
            'reason' => 'Describe the materials more clearly.',
        ]);
        Notification::assertSentTo($seller, ProductApprovalDecision::class);
    }
}
