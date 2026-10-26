<?php

namespace Tests\Feature;

use App\Models\Logistics\Area;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerShippingProvider;
use App\Services\LogisticsHubService;
use App\Services\SellerShippingProviderService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SellerShippingProvidersTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Seller $seller;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Cache::put('psgc:cities:030540000:030000000', [
            ['code' => '030540100', 'name' => 'San Fernando'],
            ['code' => '030540200', 'name' => 'Angeles'],
        ], 3600);
        Cache::put('psgc:barangays:030540100', [
            ['code' => '0305401001', 'name' => 'First'],
            ['code' => '0305401002', 'name' => 'Second'],
        ], 3600);
        Cache::put('psgc:barangays:030540200', [
            ['code' => '0305402001', 'name' => 'Angeles Brgy'],
        ], 3600);

        $this->seller = Seller::factory()->create([
            'status' => 'approved',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'municipality_code' => '030540100',
            'municipality_name' => 'San Fernando',
            'barangay_code' => '0305401001',
            'barangay_name' => 'First',
        ]);
    }

    private function operationalProvider(string $businessName = 'Alpha Cargo', string $barangay = '0305401001'): LogisticsProvider
    {
        $provider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'business_name' => $businessName,
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'province_verified_at' => now(),
        ]);

        $province = LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'name' => $businessName.' Province Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'active_province_slot' => 1,
        ]);

        $station = LogisticsHub::query()->create([
            'logistics_provider_id' => $provider->id,
            'parent_hub_id' => $province->id,
            'name' => $businessName.' Station',
            'hub_type' => LogisticsHub::STATION,
            'region_code' => '030000000',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'city_municipality_code' => '030540100',
            'municipality' => 'San Fernando',
            'barangay_code' => 'physical',
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'is_active' => true,
        ]);

        (new LogisticsHubService)->saveCoverage($provider->id, [
            'name' => 'Coverage '.$barangay,
            'station_hub_id' => $station->id,
            'city_municipality_code' => '030540100',
            'barangay_code' => $barangay,
            'is_active' => true,
        ]);

        return $provider->fresh();
    }

    public function test_page_lists_eligible_providers_in_seller_province_with_origin_coverage(): void
    {
        $alpha = $this->operationalProvider('Alpha Cargo');
        $beta = $this->operationalProvider('Beta Cargo');
        // Same province, different barangay — not eligible for this seller's pickup.
        $this->operationalProvider('Gamma Cargo', '0305401002');

        $otherProvince = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'business_name' => 'Bulacan Express',
            'province_code' => '030140000',
            'province_name' => 'Bulacan',
            'province_verified_at' => now(),
        ]);

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.shipping-providers.index'))
            ->assertOk()
            ->assertSee('Alpha Cargo')
            ->assertSee('Beta Cargo')
            ->assertDontSee('Gamma Cargo')
            ->assertDontSee('Bulacan Express');

        $eligible = app(SellerShippingProviderService::class)->eligibleProviders($this->seller);
        $this->assertTrue($eligible->contains('id', $alpha->id));
        $this->assertTrue($eligible->contains('id', $beta->id));
        $this->assertFalse($eligible->contains('id', $otherProvince->id));
    }

    public function test_seller_can_enable_and_disable_eligible_providers(): void
    {
        $alpha = $this->operationalProvider('Alpha Cargo');
        $beta = $this->operationalProvider('Beta Cargo');

        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.shipping-providers.enable', $alpha))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('seller_shipping_providers', [
            'seller_id' => $this->seller->id,
            'logistics_provider_id' => $alpha->id,
            'is_enabled' => 1,
        ]);

        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.shipping-providers.enable', $beta))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(app(SellerShippingProviderService::class)->canOfferShipping($this->seller));

        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.shipping-providers.disable', $alpha))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('seller_shipping_providers', [
            'seller_id' => $this->seller->id,
            'logistics_provider_id' => $alpha->id,
            'is_enabled' => 0,
        ]);
        $this->assertTrue(app(SellerShippingProviderService::class)->canOfferShipping($this->seller));
    }

    public function test_cannot_disable_last_enabled_eligible_provider(): void
    {
        $alpha = $this->operationalProvider('Alpha Cargo');

        app(SellerShippingProviderService::class)->enable($this->seller, $alpha);

        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.shipping-providers.disable', $alpha))
            ->assertRedirect()
            ->assertSessionHasErrors('provider');

        $this->assertTrue(
            SellerShippingProvider::query()
                ->where('seller_id', $this->seller->id)
                ->where('logistics_provider_id', $alpha->id)
                ->where('is_enabled', true)
                ->exists()
        );
    }

    public function test_cannot_enable_provider_without_origin_coverage_or_live_hub(): void
    {
        $noCoverage = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'business_name' => 'No Coverage Co',
            'province_code' => '030540000',
            'province_verified_at' => now(),
        ]);
        LogisticsHub::query()->create([
            'logistics_provider_id' => $noCoverage->id,
            'name' => 'Hub',
            'hub_type' => LogisticsHub::PROVINCE,
            'region_code' => '030000000',
            'province_code' => '030540000',
            'province_name' => 'Pampanga',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
            'active_province_slot' => 1,
        ]);

        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.shipping-providers.enable', $noCoverage))
            ->assertRedirect()
            ->assertSessionHasErrors('provider');

        $this->assertDatabaseMissing('seller_shipping_providers', [
            'seller_id' => $this->seller->id,
            'logistics_provider_id' => $noCoverage->id,
        ]);
    }

    public function test_seller_cannot_modify_another_sellers_shipping_settings(): void
    {
        $alpha = $this->operationalProvider('Alpha Cargo');
        $other = Seller::factory()->create([
            'status' => 'approved',
            'province_code' => '030540000',
            'barangay_code' => '0305401001',
        ]);

        app(SellerShippingProviderService::class)->enable($other, $alpha);

        // Acting as this seller enabling the same provider creates THIS seller's row only.
        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.shipping-providers.enable', $alpha))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SellerShippingProvider::query()
            ->where('seller_id', $this->seller->id)
            ->where('logistics_provider_id', $alpha->id)
            ->count());
        $this->assertSame(1, SellerShippingProvider::query()
            ->where('seller_id', $other->id)
            ->where('logistics_provider_id', $alpha->id)
            ->count());

        // Disabling as this seller must not clear the other seller's enablement.
        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.shipping-providers.enable', $this->operationalProvider('Beta Cargo')))
            ->assertRedirect();

        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.shipping-providers.disable', $alpha))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(
            SellerShippingProvider::query()
                ->where('seller_id', $other->id)
                ->where('logistics_provider_id', $alpha->id)
                ->where('is_enabled', true)
                ->exists()
        );
        $this->assertFalse(
            SellerShippingProvider::query()
                ->where('seller_id', $this->seller->id)
                ->where('logistics_provider_id', $alpha->id)
                ->where('is_enabled', true)
                ->exists()
        );
    }

    public function test_overlapping_providers_in_same_province_can_both_be_enabled(): void
    {
        $alpha = $this->operationalProvider('Alpha Cargo');
        $beta = $this->operationalProvider('Beta Cargo');

        $service = app(SellerShippingProviderService::class);
        $service->enable($this->seller, $alpha);
        $service->enable($this->seller, $beta);

        $enabled = $service->enabledEligibleProviders($this->seller);
        $this->assertCount(2, $enabled);
        $this->assertTrue($enabled->contains('id', $alpha->id));
        $this->assertTrue($enabled->contains('id', $beta->id));
    }

    public function test_unavailable_enabled_provider_can_be_removed_even_if_last_row(): void
    {
        $alpha = $this->operationalProvider('Alpha Cargo');
        app(SellerShippingProviderService::class)->enable($this->seller, $alpha);

        // Drop origin coverage → provider becomes ineligible.
        Area::query()
            ->where('logistics_provider_id', $alpha->id)
            ->update(['is_active' => false]);

        $this->assertFalse(app(SellerShippingProviderService::class)->canOfferShipping($this->seller));

        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.shipping-providers.disable', $alpha))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('seller_shipping_providers', [
            'seller_id' => $this->seller->id,
            'logistics_provider_id' => $alpha->id,
            'is_enabled' => 0,
        ]);
    }

    public function test_guest_cannot_access_shipping_providers(): void
    {
        $this->get(route('seller.shipping-providers.index'))
            ->assertRedirect();
    }

    public function test_empty_state_when_seller_has_no_pickup_barangay(): void
    {
        $this->seller->forceFill(['barangay_code' => null, 'barangay_name' => null])->save();

        $this->actingAs($this->seller, 'seller')
            ->get(route('seller.shipping-providers.index'))
            ->assertOk()
            ->assertSee('Pickup location incomplete');
    }
}
