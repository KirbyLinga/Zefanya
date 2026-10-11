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
     * The landing page is PUBLIC ONLY: an authenticated buyer is sent to their
     * own home (buyer.home, rendered with navbar variant 'buyer'). The redirect
     * is plain — the session must survive it, so the buyer is still
     * authenticated afterwards.
     */
    public function test_authenticated_buyer_is_redirected_from_landing_to_buyer_home(): void
    {
        $buyer = Buyer::factory()->create();

        $this->actingAs($buyer, 'buyer')
            ->get(route('home'))
            ->assertRedirect(route('buyer.home'));

        $this->assertAuthenticatedAs($buyer, 'buyer');
    }

    /**
     * An unauthenticated request to the landing page must always get the public
     * navbar (Login / Register): an approved buyer row existing in the database
     * must never leak into the navbar without a session.
     */
    public function test_guest_sees_no_account_dropdown_on_landing_page_when_a_buyer_row_exists(): void
    {
        Buyer::factory()->create(['status' => 'approved']);

        $this->assertGuest('buyer');

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('data-login-trigger', false)
            ->assertSee('Register')
            ->assertDontSee('js-account-toggle', false)
            ->assertDontSee('accountDropdown', false);
    }

    /**
     * A logged-in Seller visiting the landing page must see the public nav —
     * the landing shell always renders variant 'landing', which never reads a
     * guard, so a seller session never shows a buyer account dropdown there.
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

    /**
     * The buyer home is the authenticated surface: the navbar renders the
     * account dropdown and NOT the public Login / Register links — the
     * data-hide-guest attribute exists only on the guest Login anchor.
     */
    public function test_buyer_home_shows_buyer_navbar_and_no_login_links(): void
    {
        $buyer = Buyer::factory()->create();

        $this->actingAs($buyer, 'buyer')
            ->get(route('buyer.home'))
            ->assertOk()
            ->assertSee('accountDropdown', false)
            ->assertDontSee('data-hide-guest="true"', false);
    }
}
