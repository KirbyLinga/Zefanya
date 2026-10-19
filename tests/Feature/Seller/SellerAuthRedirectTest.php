<?php

namespace Tests\Feature\Seller;

use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SellerAuthRedirectTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_authenticated_seller_can_access_dashboard(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        $this->actingAs($seller, 'seller')
            ->get(route('seller.dashboard'))
            ->assertStatus(200);
    }

    public function test_authenticated_seller_can_access_products(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        $this->actingAs($seller, 'seller')
            ->get(route('seller.products.index'))
            ->assertStatus(200);
    }

    public function test_authenticated_seller_visiting_login_page_is_redirected_to_dashboard(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        $this->actingAs($seller, 'seller')
            ->get(route('seller.login'))
            ->assertRedirect(route('seller.dashboard'));
    }

    public function test_authenticated_seller_visiting_inventory_is_redirected_to_products(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        $this->actingAs($seller, 'seller')
            ->get(route('seller.inventory.index'))
            ->assertRedirect(route('seller.products.index'));
    }

    public function test_authenticated_seller_is_not_redirected_to_buyer_or_login_on_seller_routes(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        $routes = [
            'seller.orders.index',
            'seller.shipments.index',
            'seller.vouchers.index',
            'seller.reports.index',
            'seller.feedback.index',
            'seller.chat.index',
            'seller.account.index',
        ];

        foreach ($routes as $routeName) {
            $response = $this->actingAs($seller, 'seller')->get(route($routeName));

            $response->assertStatus(200);
            $location = $response->headers->get('Location');
            $this->assertTrue(
                $location === null
                    || (! str_contains($location, '/buyer') && ! str_contains($location, '/login')),
                "Route {$routeName} unexpectedly redirected to {$location}"
            );
        }
    }

    public function test_guest_visiting_dashboard_is_redirected_to_seller_login(): void
    {
        $this->get(route('seller.dashboard'))
            ->assertRedirect(route('seller.login'));
    }
}
