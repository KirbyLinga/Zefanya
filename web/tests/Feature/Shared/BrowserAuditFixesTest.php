<?php

namespace Tests\Feature\Shared;

use App\Enums\ParcelReturnStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\ParcelReturn;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Carbon;

/** Defects found in the Buyer/Seller/Logistics browser audit. */
class BrowserAuditFixesTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_get_login_opens_the_home_login_modal_instead_of_a_405(): void
    {
        $this->get('/login')->assertRedirect('/?login=1');
    }

    public function test_footer_has_no_coming_soon_badges_and_one_help_link(): void
    {
        $html = $this->withoutVite()->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('Coming soon', $html);
        $this->assertStringNotContainsString('Download App', $html);
        $this->assertSame(1, substr_count($html, '>Help Center<'));
        $this->assertStringNotContainsString('>How to Buy<', $html);
    }

    public function test_messages_popup_stays_closed_until_the_buyer_opens_it(): void
    {
        $this->withoutVite();
        $buyer = Buyer::factory()->create();

        $html = $this->actingAs($buyer, 'buyer')->get(route('buyer.cart.index'))->assertOk()->getContent();

        $this->assertStringContainsString("if (recall('state') === 'open') setState('open');", $html);
        $this->assertStringNotContainsString("['closed', 'minimized'].includes(recall('state'))", $html);
    }

    private function buyerReturn(array $attributes): ParcelReturn
    {
        $return = (new ParcelReturn)->forceFill(['status' => ParcelReturnStatus::Requested->value, 'seller_response_due_at' => Carbon::parse('2026-11-16 12:00:00', 'UTC')] + $attributes);
        $return->setRelation('events', collect());

        return $return;
    }

    public function test_logistics_return_badge_reflects_the_real_stage(): void
    {
        $this->assertSame('Awaiting seller decision', $this->buyerReturn(['seller_decision' => null])->logisticsStageLabel());
        $this->assertSame('Approved · needs a pickup rider', $this->buyerReturn(['seller_decision' => 'approved'])->logisticsStageLabel());
        $this->assertSame('Approved · pickup rider assigned', $this->buyerReturn(['seller_decision' => 'approved', 'collection_courier_id' => 5])->logisticsStageLabel());
        $this->assertSame(
            'Collected from Buyer · Awaiting station hand-in',
            $this->buyerReturn(['seller_decision' => 'approved', 'collected_at' => Carbon::parse('2026-11-13 06:25:14', 'UTC')])->logisticsStageLabel(),
        );
        $this->assertNotSame('Return requested', $this->buyerReturn(['seller_decision' => null])->logisticsStageLabel());
    }

    public function test_unknown_pages_show_a_branded_404_with_a_way_home(): void
    {
        $this->get('/no-such-page')->assertNotFound()
            ->assertSee("We can't find that page", false)->assertSee('Go to home')->assertSee('Go back')
            ->assertDontSee('Seller dashboard');
    }

    public function test_panel_404_links_back_to_that_panels_dashboard(): void
    {
        $this->get('/seller/no-such-page')->assertNotFound()->assertSee('Seller dashboard')->assertSee(url('/seller/dashboard'), false);
        $this->get('/logistics/no-such-page')->assertNotFound()->assertSee(url('/logistics'), false);
    }

    public function test_wrong_method_shows_a_branded_405_not_the_framework_page(): void
    {
        $this->get('/logout')->assertStatus(405)
            ->assertSee("That action isn't available here", false)
            ->assertDontSee('Oops! An Error Occurred');
    }

    public function test_every_error_template_renders_without_a_session(): void
    {
        foreach ([401, 403, 404, 405, 419, 429, 500, 503] as $code) {
            $html = view("errors.$code")->render();
            $this->assertStringContainsString((string) $code, $html);
            $this->assertStringContainsString('Go to home', $html);
        }
    }
}
