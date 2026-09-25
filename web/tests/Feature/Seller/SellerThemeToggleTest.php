<?php

namespace Tests\Feature\Seller;

use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SellerThemeToggleTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_seller_dashboard_ships_the_theme_toggle_and_head_init(): void
    {
        $seller = Seller::factory()->create();

        $response = $this->actingAs($seller, 'seller')->get(route('seller.dashboard'));

        $response->assertOk();

        $content = $response->getContent();

        // The toggle button renders in the sidebar footer.
        $this->assertStringContainsString('data-theme-toggle', $content);
        $this->assertStringContainsString('aria-pressed="false"', $content);
        $this->assertStringContainsString('Switch to dark mode', $content);

        // The no-flash init script lives in the head, before any stylesheet,
        // and reads the persisted theme key.
        $initAt = mb_strpos($content, "localStorage.getItem('zf-theme')");
        $this->assertNotFalse($initAt, 'Theme init script must be present.');
        $firstStylesheet = mb_strpos($content, 'rel="stylesheet"');
        $this->assertNotFalse($firstStylesheet);
        $this->assertLessThan($firstStylesheet, $initAt, 'Init script must run before the first stylesheet.');
    }
}
