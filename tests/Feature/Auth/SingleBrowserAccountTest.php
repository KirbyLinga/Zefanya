<?php

namespace Tests\Feature\Auth;

use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class SingleBrowserAccountTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_visiting_a_buyer_page_ends_a_stale_seller_session_in_the_same_browser(): void
    {
        $buyer = Buyer::factory()->create();
        $seller = Seller::factory()->create();

        $this->actingAs($buyer, 'buyer');
        $this->actingAs($seller, 'seller');

        $this->get(route('buyer.home'))->assertOk();

        $this->assertAuthenticated('buyer');
        $this->assertGuest('seller');
    }
}
