<?php

namespace Tests\Feature\Components;

use App\Models\Buyer\Buyer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class NavbarTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_see_login_and_register_links_on_the_landing_page(): void
    {
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('data-login-trigger', false);
        $response->assertSee('Register');
        $response->assertDontSee('js-account-toggle', false);
        $response->assertDontSee('accountDropdown', false);
    }

    public function test_signed_in_buyers_see_account_dropdown_instead_of_login_and_register(): void
    {
        $buyer = Buyer::factory()->create([
            'first_name' => 'Ben',
            'last_name' => 'Dela Cruz',
        ]);

        $response = $this->actingAs($buyer, 'buyer')->get(route('home'));

        $response->assertOk();
        $response->assertSee('js-account-toggle', false);
        $response->assertSee('accountDropdown', false);
        $response->assertSee('Ben Dela Cruz');
        $response->assertSee('Logout');
        $response->assertSee('action="'.url('/logout').'"', false);
    }

    public function test_buyer_can_log_out_and_navbar_returns_to_guest_state(): void
    {
        $buyer = Buyer::factory()->create();

        $response = $this->actingAs($buyer, 'buyer')->post(route('unified.logout'));

        $response->assertRedirect(route('home'));
        $this->assertGuest('buyer');

        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('data-login-trigger', false);
        $response->assertDontSee('js-account-toggle', false);
    }
}
