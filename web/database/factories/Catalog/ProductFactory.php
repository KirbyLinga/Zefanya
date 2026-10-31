<?php

namespace Database\Factories\Catalog;

use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Category-appropriate parcel weight ranges in grams. `products.weight_grams`
     * feeds SellerOrder::totalWeightGrams() and CourierAssignmentService, so real
     * weights keep courier assignment behaving like production data.
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private const WEIGHT_RANGES = [
        'Books and Media' => [250, 1800],
        'Health and Beauty' => [120, 900],
        'Sports and Outdoors' => [400, 6000],
        'Home and Garden' => [300, 9000],
        'Kids and Baby' => [150, 3500],
        'Men\'s Apparel' => [200, 1200],
        'Women\'s Apparel' => [150, 900],
        'Electronics and Gadgets' => [180, 3200],
        'Pet Supplies' => [300, 7000],
        'Food and Gourmet' => [400, 5200],
        'Automotive & Motorcycle' => [500, 8000],
        'Furniture and Office Equipment' => [4000, 18000],
        'Jewelry and Watches' => [40, 400],
        'Office and School Supplies' => [120, 2500],
    ];

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $category = Category::query()->inRandomOrder()->first(['id', 'label']);
        $label = $category?->label;
        $name = $this->nameFor($label);
        $weightGrams = $this->weightFor($label);

        return [
            'seller_id' => Seller::factory(),
            'category_id' => $category?->id,
            'name' => $name,
            'slug' => $this->slugFor($name),
            'description' => $this->descriptionFor($name, $label),
            'price' => fake()->randomElement(ProductCatalogData::PRICE_POINTS),
            'stock_quantity' => fake()->numberBetween(12, 60),
            'low_stock_threshold' => 5,
            'weight_grams' => $weightGrams,
            'dimensions' => $this->dimensionsFor($weightGrams),
            'status' => 'active',
        ];
    }

    /**
     * Lock the product to one category (the real create flow uses the seller's
     * line of business as category_id) and pick a matching name and weight.
     */
    public function forCategory(?Category $category): static
    {
        return $this->state(function () use ($category): array {
            $label = $category?->label;
            $name = $this->nameFor($label);
            $weightGrams = $this->weightFor($label);

            return [
                'category_id' => $category?->id,
                'name' => $name,
                'slug' => $this->slugFor($name),
                'description' => $this->descriptionFor($name, $label),
                'weight_grams' => $weightGrams,
                'dimensions' => $this->dimensionsFor($weightGrams),
            ];
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'inactive',
        ]);
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'draft',
        ]);
    }

    public function outOfStock(): static
    {
        return $this->state(fn (array $attributes): array => [
            'stock_quantity' => 0,
        ]);
    }

    private function nameFor(?string $label): string
    {
        return fake()->randomElement(ProductCatalogData::namesFor($label));
    }

    private function weightFor(?string $label): int
    {
        $range = self::WEIGHT_RANGES[$label] ?? [150, 2000];

        return fake()->numberBetween($range[0], $range[1]);
    }

    private function descriptionFor(string $name, ?string $label): string
    {
        return str_replace(
            [':name', ':category'],
            [$name, $label ?? 'the Zefanya catalog'],
            fake()->randomElement(ProductCatalogData::DESCRIPTION_TEMPLATES),
        );
    }

    /**
     * Centimetre box derived from the parcel weight — 300 g ≈ 16 cm and 15 kg
     * ≈ 59 cm on the longest side.
     *
     * @return array{length: int, width: int, height: int}
     */
    private function dimensionsFor(int $weightGrams): array
    {
        $side = max(6, (int) round(($weightGrams ** (1 / 3)) * 2.4));

        return [
            'length' => $side + random_int(2, 10),
            'width' => $side,
            'height' => max(1, $side - random_int(0, 3)),
        ];
    }

    /**
     * Batch-safe slug: every definition() call runs BEFORE any row of the
     * batch is inserted, so a DB exists() check cannot see siblings created
     * in the same count(N) call (all would return the same base slug and the
     * second INSERT fails). A random suffix keeps realistic readable slugs
     * while staying collision-free; ProductSeeder paths hit the same helper.
     */
    private function slugFor(string $name): string
    {
        $base = Str::slug($name) ?: 'product';

        return $base.'-'.Str::lower(Str::random(6));
    }
}
