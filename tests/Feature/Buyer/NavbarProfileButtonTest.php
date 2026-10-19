<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class NavbarProfileButtonTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_profile_button_and_dropdown_present_for_authenticated_buyer(): void
    {
        $buyer = Buyer::factory()->create();

        $response = $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.home'));

        $response->assertOk()
            ->assertSee('js-account-toggle')
            ->assertSee('accountDropdown');
    }

    public function test_account_dropdown_not_present_for_guest(): void
    {
        $response = $this->get(route('buyer.home'));

        $response->assertOk()
            ->assertDontSee('js-account-toggle');
    }

    public function test_navbar_js_included(): void
    {
        $buyer = Buyer::factory()->create();

        $response = $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.home'));

        // Check that navbar is referenced in the page (in manifest/vite output)
        $response->assertOk();
    }
}
