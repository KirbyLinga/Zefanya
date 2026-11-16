<?php

namespace Tests\Feature\Seller;

use App\Enums\StoreSaleStatus;
use App\Enums\StoreSaleType;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Seller\Seller;
use App\Models\Seller\StoreSale;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreSaleModelTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{0: StoreSaleType, 1: int|float|string|null, 2: bool}> */
    public static function values(): array
    {
        return [
            'percent 1' => [StoreSaleType::Percent, 1, true],
            'percent 99' => [StoreSaleType::Percent, 99, true],
            'percent 0' => [StoreSaleType::Percent, 0, false],
            'percent 100' => [StoreSaleType::Percent, 100, false],
            'percent fraction' => [StoreSaleType::Percent, 12.5, false],
            'percent text fraction' => [StoreSaleType::Percent, '12.5', false],
            'percent missing' => [StoreSaleType::Percent, null, false],
            'fixed price 1 centavo' => [StoreSaleType::FixedPrice, 1, true],
            'fixed price above 99' => [StoreSaleType::FixedPrice, 150000, true],
            'fixed price zero' => [StoreSaleType::FixedPrice, 0, false],
            'fixed price negative' => [StoreSaleType::FixedPrice, -100, false],
            'fixed price fraction' => [StoreSaleType::FixedPrice, 100.5, false],
        ];
    }

    #[DataProvider('values')]
    public function test_discount_value_rules(StoreSaleType $type, int|float|string|null $value, bool $accepted): void
    {
        if (! $accepted) {
            $this->expectException(\InvalidArgumentException::class);
        }

        $sale = StoreSale::factory()->create(['type' => $type, 'value' => $value]);

        $this->assertSame($type, $sale->fresh()->type);
        $this->assertSame($value, $sale->fresh()->value);
    }

    public function test_sale_can_target_a_whole_product_or_one_of_its_variants(): void
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        $variant = ProductVariant::query()->where('product_id', $product->id)->firstOrFail();

        $wholeProduct = StoreSale::factory()->create(['seller_id' => $seller->id, 'product_id' => $product->id]);
        $oneVariant = StoreSale::factory()->create(['seller_id' => $seller->id, 'product_id' => $product->id, 'variant_id' => $variant->id]);

        $this->assertNull($wholeProduct->fresh()->variant);
        $this->assertTrue($oneVariant->fresh()->variant->is($variant));
        $this->assertTrue($oneVariant->fresh()->product->is($product));
        $this->assertTrue($oneVariant->fresh()->seller->is($seller));
    }

    public function test_sale_cannot_target_another_sellers_product(): void
    {
        $product = Product::factory()->create(['seller_id' => Seller::factory()->create()->id]);

        $this->expectException(\InvalidArgumentException::class);

        StoreSale::factory()->create(['seller_id' => Seller::factory()->create()->id, 'product_id' => $product->id]);
    }

    public function test_sale_variant_must_belong_to_the_sale_product(): void
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        $otherProduct = Product::factory()->create(['seller_id' => $seller->id]);
        $foreignVariant = ProductVariant::query()->where('product_id', $otherProduct->id)->firstOrFail();

        $this->expectException(\InvalidArgumentException::class);

        StoreSale::factory()->create(['seller_id' => $seller->id, 'product_id' => $product->id, 'variant_id' => $foreignVariant->id]);
    }

    public function test_sale_must_end_after_it_starts(): void
    {
        $at = now();

        $this->expectException(\InvalidArgumentException::class);

        StoreSale::factory()->create(['starts_at' => $at, 'ends_at' => $at]);
    }

    public function test_deleting_the_variant_removes_the_sale_instead_of_widening_it(): void
    {
        $seller = Seller::factory()->create();
        $product = Product::factory()->create(['seller_id' => $seller->id]);
        $variant = ProductVariant::query()->where('product_id', $product->id)->firstOrFail();
        $sale = StoreSale::factory()->create(['seller_id' => $seller->id, 'product_id' => $product->id, 'variant_id' => $variant->id]);

        $variant->delete();

        $this->assertNull($sale->fresh());
    }

    public function test_status_moves_through_the_schedule_and_matches_the_scopes(): void
    {
        $start = Carbon::parse('2026-11-01 10:00:00');
        $end = Carbon::parse('2026-11-03 10:00:00');
        Carbon::setTestNow($start->copy()->subDay());
        $sale = StoreSale::factory()->create(['starts_at' => $start, 'ends_at' => $end]);

        $moments = [
            [$start->copy()->subSecond(), StoreSaleStatus::Upcoming, 'upcoming'],
            [$start, StoreSaleStatus::Ongoing, 'ongoing'],
            [$end->copy()->subSecond(), StoreSaleStatus::Ongoing, 'ongoing'],
            [$end, StoreSaleStatus::Ended, 'ended'],
        ];

        foreach ($moments as [$moment, $status, $scope]) {
            Carbon::setTestNow($moment);

            $this->assertSame($status, $sale->status());

            foreach (['upcoming', 'ongoing', 'ended'] as $name) {
                $this->assertSame($name === $scope ? 1 : 0, StoreSale::query()->{$name}()->count(), "{$name} at {$moment}");
            }
        }

        Carbon::setTestNow();
    }

    public function test_status_cannot_be_written(): void
    {
        $sale = StoreSale::factory()->create();

        $sale->fill(['status' => 'ended']);

        $this->assertSame(StoreSaleStatus::Ongoing, $sale->status());
        $this->assertArrayNotHasKey('status', $sale->getAttributes());
    }
}
