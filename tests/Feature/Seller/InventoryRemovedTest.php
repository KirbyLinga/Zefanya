<?php

namespace Tests\Feature\Seller;

use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class InventoryRemovedTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function approvedSeller(): Seller
    {
        return Seller::factory()->create();
    }

    public function test_dashboard_html_has_no_link_to_inventory_route(): void
    {
        $seller = $this->approvedSeller();
        $html = $this->actingAs($seller, 'seller')->get(route('seller.dashboard'))->getContent();

        $this->assertStringNotContainsString('href="'.route('seller.inventory.index').'"', $html);
        $this->assertStringNotContainsString('seller/inventory', $html);
    }

    public function test_products_page_html_has_no_link_to_inventory_route(): void
    {
        $seller = $this->approvedSeller();
        $html = $this->actingAs($seller, 'seller')->get(route('seller.products.index'))->getContent();

        $this->assertStringNotContainsString('href="'.route('seller.inventory.index').'"', $html);
        $this->assertStringNotContainsString('seller/inventory', $html);
    }

    public function test_get_inventory_redirects_to_products_as_approved_seller(): void
    {
        $seller = $this->approvedSeller();

        $response = $this->actingAs($seller, 'seller')->get(route('seller.inventory.index'));

        $response->assertRedirect(route('seller.products.index'));
        $response->assertStatus(302);
    }

    public function test_get_inventory_redirects_guests_to_seller_login_route(): void
    {
        $response = $this->get(route('seller.inventory.index'));

        $response->assertRedirect(route('seller.login'));
        $response->assertStatus(302);
    }
}
