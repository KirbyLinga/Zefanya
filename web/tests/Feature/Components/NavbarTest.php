<?php

namespace Tests\Feature\Components;

use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class NavbarTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ── Landing page ──────────────────────────────────────────────────────────

    public function test_guests_see_login_and_register_links_on_the_landing_page(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-login-trigger', false)
            ->assertSee('Register')
            ->assertDontSee('js-account-toggle', false)
            ->assertDontSee('accountDropdown', false);
    }

    /**
     * An authenticated buyer visiting the landing page sees their account
     * dropdown, not the Login / Register links.
     * The landing layout uses variant='landing', which now checks the buyer
     * guard the same way variant='buyer' does.
     */
    public function test_signed_in_buyer_sees_account_dropdown_on_landing_page(): void
    {
        $buyer = Buyer::factory()->create(['first_name' => 'Ben', 'last_name' => 'Dela Cruz']);

        $this->actingAs($buyer, 'buyer')
            ->get(route('home'))
            ->assertOk()
            ->assertSee('js-account-toggle', false)
            ->assertSee('accountDropdown', false)
            ->assertSee('Ben Dela Cruz')
            ->assertSee('Logout');
    }

    /**
     * A logged-in Seller visiting the landing page must see the guest nav —
     * the navbar checks the buyer guard only, so a seller session must never
     * cause the buyer account dropdown to appear.
     */
    public function test_authenticated_seller_sees_guest_nav_on_landing_page(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);

        $this->actingAs($seller, 'seller')
            ->get(route('home'))
            ->assertOk()
            ->assertDontSee('js-account-toggle', false)
            ->assertDontSee('accountDropdown', false)
            ->assertSee('data-login-trigger', false);
    }

    // ── Logout ────────────────────────────────────────────────────────────────

    public function test_buyer_can_log_out_and_landing_page_returns_to_guest_nav(): void
    {
        $buyer = Buyer::factory()->create();

        $this->actingAs($buyer, 'buyer')
            ->post(route('unified.logout'))
            ->assertRedirect(route('home'));

        $this->assertGuest('buyer');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-login-trigger', false)
            ->assertDontSee('js-account-toggle', false);
    }

    // ── Buyer area ────────────────────────────────────────────────────────────

    public function test_authenticated_buyer_sees_account_dropdown_on_buyer_home(): void
    {
        $buyer = Buyer::factory()->create(['first_name' => 'Ben', 'last_name' => 'Dela Cruz']);

        $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.home'))
            ->assertOk()
            ->assertSee('js-account-toggle', false)
            ->assertSee('accountDropdown', false)
            ->assertSee('Ben Dela Cruz')
            ->assertSee('Logout');
    }
}
