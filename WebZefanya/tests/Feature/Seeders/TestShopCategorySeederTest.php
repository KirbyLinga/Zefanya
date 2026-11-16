<?php

namespace Tests\Feature\Seeders;

use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Database\Seeders\TestShopCategorySeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class TestShopCategorySeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_each_test_shop_gets_a_different_category_and_misfiled_products_move_once(): void
    {
        $books = Category::query()->firstOrCreate(['label' => 'Books and Media'], ['icon' => 'book']);
        foreach (['Health and Beauty', 'Home and Garden', 'Electronics and Gadgets'] as $label) {
            Category::query()->firstOrCreate(['label' => $label], ['icon' => 'tag']);
        }
        $sellers = collect(range(1, 4))->map(fn (int $n) => Seller::factory()->create(['email' => "seller{$n}@zefanya.test", 'line_of_business_id' => $books->id]));
        $other = Seller::factory()->create(['line_of_business_id' => $books->id]);
        $book = Product::factory()->create(['seller_id' => $sellers[0]->id, 'category_id' => $books->id, 'slug' => 'atomic-habits-james-clear']);
        $cases = Product::factory()->create(['seller_id' => $sellers[0]->id, 'category_id' => $books->id, 'slug' => 'game-cases']);

        $this->seed(TestShopCategorySeeder::class);

        $labels = $sellers->map(fn (Seller $s) => $s->fresh()->lineOfBusiness?->label ?? Category::query()->find($s->fresh()->line_of_business_id)->label)->all();
        $this->assertSame(array_values(TestShopCategorySeeder::SHOPS), $labels);
        $this->assertCount(4, array_unique($labels));
        $this->assertSame($books->id, (int) $other->fresh()->line_of_business_id, 'Other Sellers are untouched.');
        $this->assertSame($books->id, (int) $book->fresh()->category_id, 'Correctly filed products stay.');
        $this->assertSame('Electronics and Gadgets', Category::query()->find($cases->fresh()->category_id)->label);

        $seeder = app(TestShopCategorySeeder::class);
        $seeder->run();
        $this->assertSame([], $seeder->changes, 'A second run changes nothing.');
    }
}
