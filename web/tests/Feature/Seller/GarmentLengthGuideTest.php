<?php

namespace Tests\Feature\Seller;

use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use App\Support\SizeGuide;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ValidProductPublication;
use Tests\TestCase;

/** Sellers pick an optional garment type; Buyers see its length and a guide table. */
class GarmentLengthGuideTest extends TestCase
{
    use LazilyRefreshDatabase;
    use ValidProductPublication;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('public');
        SizeGuide::flushColumnCache();
        $this->withoutExceptionHandling();
    }

    private function seller(string $categoryLabel): Seller
    {
        $category = Category::query()->where('label', $categoryLabel)->first()
            ?? Category::query()->create(['icon' => 'shirt', 'label' => $categoryLabel]);

        return Seller::factory()->create(['status' => 'approved', 'line_of_business_id' => $category->id]);
    }

    private function payload(array $extra = []): array
    {
        return [
            ...$this->publicationDefaults(),
            'name' => 'Casual summer dress', 'price' => 599, 'weight' => 300, 'weight_unit' => 'g', 'weight_grams' => 300,
            'stock_quantity' => 10, 'status' => 'active',
            'images' => [UploadedFile::fake()->createWithContent('cover.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='))],
            ...$extra,
        ];
    }

    private function publish(Seller $seller, array $extra = []): Product
    {
        $this->actingAs($seller, 'seller')->post(route('seller.products.store'), $this->payload($extra))->assertSessionHasNoErrors();

        return Product::query()->latest('id')->firstOrFail();
    }

    private function approve(Product $product): void
    {
        $product->forceFill(['status' => 'active', 'approved_at' => now()])->save();
    }

    public function test_apparel_seller_saves_a_garment_type_and_buyers_see_it_with_the_guide(): void
    {
        $product = $this->publish($this->seller("Women's Apparel"), ['garment_type' => 'dress_midi']);
        $this->approve($product);

        $this->assertSame('dress_midi', $product->fresh()->garment_type);

        $html = $this->get(route('buyer.products.show', $product))->assertOk()->getContent();
        $this->assertStringContainsString('data-garment-type', $html);
        $this->assertStringContainsString('Midi', $html);
        $this->assertStringContainsString('105-125 cm', $html);
        $this->assertStringContainsString('data-length-guide', $html);
        // Every dress type is listed; the product's own row is marked.
        foreach (['Mini', 'Knee-length', 'Maxi'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('aria-current="true"', $html);
        // Tops are not shown for a dress.
        $this->assertStringNotContainsString('Crop top', $html);
    }

    public function test_a_top_shows_the_tops_table(): void
    {
        $product = $this->publish($this->seller("Women's Apparel"), ['garment_type' => 'top_crop']);
        $this->approve($product);

        $html = $this->get(route('buyer.products.show', $product))->assertOk()->getContent();

        $this->assertStringContainsString('Crop top', $html);
        $this->assertStringContainsString('Tunic / longline', $html);
        $this->assertStringNotContainsString('Knee-length', $html);
    }

    public function test_garment_type_is_optional_and_a_listing_without_one_shows_no_guide(): void
    {
        $product = $this->publish($this->seller("Women's Apparel"));
        $this->approve($product);

        $this->assertNull($product->fresh()->garment_type);
        $html = $this->get(route('buyer.products.show', $product))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-length-guide', $html);
        $this->assertStringNotContainsString('data-garment-type', $html);
    }

    public function test_an_unknown_garment_type_is_rejected(): void
    {
        $this->withExceptionHandling();
        $this->actingAs($this->seller("Women's Apparel"), 'seller')
            ->post(route('seller.products.store'), $this->payload(['garment_type' => 'dress_gigantic']))
            ->assertSessionHasErrors('garment_type');
        $this->assertSame(0, Product::query()->count());
    }

    public function test_the_garment_type_is_ignored_outside_apparel_categories(): void
    {
        $product = $this->publish($this->seller('Electronics and Gadgets'), ['garment_type' => 'dress_mini']);

        $this->assertNull($product->fresh()->garment_type);
    }

    public function test_seller_form_offers_the_choices_for_the_apparel_seller(): void
    {
        $html = $this->actingAs($this->seller("Women's Apparel"), 'seller')->get(route('seller.products.index', ['create' => 1]))->assertOk()->getContent();

        $this->assertStringContainsString('name="garment_type"', $html);
        $this->assertStringContainsString('Maxi — 130-155 cm (51-61 in)', $html);
    }

    public function test_changing_the_garment_type_of_an_approved_listing_goes_through_review(): void
    {
        $seller = $this->seller("Women's Apparel");
        $product = $this->publish($seller, ['garment_type' => 'dress_mini']);
        $this->approve($product);
        $product->refresh();

        $this->assertTrue(app(\App\Services\Seller\ProductApprovalService::class)->isMaterial($product, ['garment_type' => 'dress_maxi']));
        $this->assertFalse(app(\App\Services\Seller\ProductApprovalService::class)->isMaterial($product, ['garment_type' => 'dress_mini']));
    }
}
