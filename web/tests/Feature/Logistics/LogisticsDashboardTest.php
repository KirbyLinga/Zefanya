<?php

namespace Tests\Feature\Logistics;

use App\Enums\LogisticsProviderStatus;
use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Seller\StoreSale;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Verifies the logistics dashboard (seller-mirrored layout) renders for an
 * approved logistics provider, is gated correctly for everyone else, and
 * shows honest empty states — no fake operational numbers, since no
 * courier/parcel/delivery tables exist yet.
 */
class LogisticsDashboardTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function approvedLogisticsProvider(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'email' => 'claire@example.com',
            'business_name' => 'Cruz Logistics Services',
            'status' => LogisticsProviderStatus::Approved->value,
            'email_verified_at' => now(),
        ]);
    }

    private function pendingApprovalProvider(): LogisticsProvider
    {
        return LogisticsProvider::factory()->pending()->create();
    }

    public function test_guest_is_redirected_to_home_login_modal(): void
    {
        $this->get(route('logistics.dashboard'))
            ->assertRedirect(route('home', ['login' => 1]));
    }

    public function test_non_approved_provider_is_bounced_to_home_login_modal(): void
    {
        $this->actingAs($this->pendingApprovalProvider(), 'logistics')
            ->get(route('logistics.dashboard'))
            ->assertRedirect(route('home', ['login' => 1]));
    }

    public function test_dashboard_renders_for_an_approved_provider(): void
    {
        $provider = $this->approvedLogisticsProvider();

        $this->actingAs($provider, 'logistics')
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->assertSee('Welcome back, '.$provider->business_name)
            ->assertSee('data-theme-toggle', true);
    }

    public function test_dashboard_shows_honest_empty_states_without_fake_numbers(): void
    {
        $html = $this->actingAs($this->approvedLogisticsProvider(), 'logistics')
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->getContent();

        // Empty states are shown for every panel that has no backing data.
        $this->assertStringContainsString('No delivery data yet', $html);
        $this->assertStringContainsString('No pending applications', $html);
        $this->assertStringContainsString('No shipments for this facility', $html);

        // Stat cards render the null placeholder, never a fabricated value.
        $this->assertStringContainsString('Parcels in hub', $html);
        $this->assertStringNotContainsString('ZEF-9824', $html);
        $this->assertStringNotContainsString('842 parcels', $html);
    }

    public function test_sidebar_links_only_to_wired_routes_without_fake_badges(): void
    {
        $html = $this->actingAs($this->approvedLogisticsProvider(), 'logistics')
            ->get(route('logistics.dashboard'))
            ->assertOk()
            ->getContent();

        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);

        foreach (['logistics.dashboard' => 'Dashboard', 'logistics.riders.index' => 'Riders'] as $name => $label) {
            $this->assertSame(1, $xpath->query('//*[@id="logisticsNav"]//a[@href="'.route($name).'" and normalize-space(.)="'.$label.'"]')->length);
        }

        foreach ($xpath->query('//*[@id="logisticsNav"]//a') as $link) {
            $href = $link->getAttribute('href');
            $matchedRoute = Route::getRoutes()->match(Request::create($href, 'GET'));

            $this->assertTrue(str_starts_with($matchedRoute->getName() ?? '', 'logistics.'), $href);
            $this->assertSame(route($matchedRoute->getName()), $href);
        }

        // The old stub's fabricated nav badges are gone.
        $this->assertStringNotContainsString('8 new', $html);
        $this->assertStringNotContainsString('lg-nav-badge', $html);
    }

    public function test_dashboard_cards_match_provider_scoped_lists_with_inclusive_dates(): void
    {
        $provider = $this->approvedLogisticsProvider();
        $other = LogisticsProvider::factory()->create();
        $start = CarbonImmutable::parse('2026-10-20', StoreSale::TIMEZONE)->startOfDay()->setTimezone(config('app.timezone'));
        $end = $start->addDay()->subSecond();
        foreach ([$provider, $other] as $owner) {
            foreach ([$start->subSecond(), $start, $end, $end->addSecond()] as $time) {
                Courier::factory()->create([
                    'logistics_provider_id' => $owner->id,
                    'updated_at' => $time,
                ]);
                foreach ([OrderStatus::AtSortingCenter, OrderStatus::PickedUp, OrderStatus::Completed] as $status) {
                    $parcel = SellerOrder::factory()->create();
                    $parcel->forceFill([
                        'status' => $status,
                        'custody_provider_id' => $owner->id,
                        'updated_at' => $time,
                    ])->save();
                    if ($status === OrderStatus::Completed) {
                        foreach ([OrderStatus::Delivered, OrderStatus::Completed] as $eventStatus) {
                            $event = new StatusHistory;
                            $event->forceFill([
                                'seller_order_id' => $parcel->id,
                                'from_status' => OrderStatus::OutForDelivery,
                                'to_status' => $eventStatus,
                                'created_at' => $time,
                                'updated_at' => $time,
                            ])->save();
                        }
                    }
                }
            }
        }

        foreach (['hub' => 'parcelsInHub', 'transit' => 'parcelsInTransit', 'delivered' => 'deliveredToday', 'riders' => 'activeRiders'] as $metric => $count) {
            $response = $this->actingAs($provider, 'logistics')->get(route('logistics.dashboard', [
                'metric' => $metric, 'from' => '2026-10-20', 'to' => '2026-10-20',
            ]))->assertOk()->assertViewHas($count, 2);
            $results = $response->viewData('metricResults');
            $this->assertSame(2, $results->total());
            foreach ($results as $result) {
                $this->assertSame($provider->id, $metric === 'riders' ? $result->logistics_provider_id : $result->custody_provider_id);
            }
        }
    }

    public function test_dashboard_rejects_reversed_date_ranges(): void
    {
        $this->actingAs($this->approvedLogisticsProvider(), 'logistics')
            ->get(route('logistics.dashboard', ['from' => '2026-10-21', 'to' => '2026-10-20']))
            ->assertSessionHasErrors('to');
    }

    public function test_dashboard_allows_one_sided_date_filters(): void
    {
        $provider = $this->approvedLogisticsProvider();
        foreach (['from', 'to'] as $boundary) {
            $this->actingAs($provider, 'logistics')
                ->get(route('logistics.dashboard', [$boundary => '2026-10-20', 'metric' => 'delivered']))
                ->assertOk();
        }
    }

    public function test_dashboard_cannot_open_another_providers_facility(): void
    {
        $hubId = DB::table('logistics_hubs')->insertGetId([
            'logistics_provider_id' => LogisticsProvider::factory()->create()->id,
            'name' => 'Other provider facility',
            'province_name' => 'Pampanga',
            'municipality' => 'Angeles',
            'hub_type' => LogisticsHub::STATION,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->actingAs($this->approvedLogisticsProvider(), 'logistics')
            ->get(route('logistics.dashboard', ['facility' => 'station:'.$hubId]))
            ->assertNotFound();
    }
}
