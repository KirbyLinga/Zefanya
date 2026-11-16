<?php

namespace Tests\Feature\Buyer;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Vite;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BuyerSearchAssetsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function buyerPages(): array
    {
        return [['buyer.home'], ['buyer.products.index'], ['buyer.search.index'], ['buyer.categories.index']];
    }

    #[DataProvider('buyerPages')]
    public function test_buyer_pages_load_one_search_owner_and_keep_the_shared_controls(string $route): void
    {
        $response = $this->get(route($route))->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $search = app(Vite::class)->asset('resources/js/buyer/search.js');
        $compatibility = app(Vite::class)->asset('resources/js/buyer/navbar-search.js');

        $this->assertSame(1, $xpath->query('//script[@src="'.$search.'"]')->length);
        $this->assertSame(0, $xpath->query('//script[@src="'.$compatibility.'"]')->length);
        $this->assertSame(1, $xpath->query('//input[contains(@class,"zf-search-input")]')->length);
        $this->assertSame(1, $xpath->query('//button[contains(@class,"zf-search-btn")][@type="button"]')->length);
    }
}
