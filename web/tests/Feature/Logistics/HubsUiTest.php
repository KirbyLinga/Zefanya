<?php

namespace Tests\Feature\Logistics;

use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

class HubsUiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function province(LogisticsProvider $provider, string $name = 'Pampanga Hub'): LogisticsHub
    {
        return LogisticsHub::create([
            'logistics_provider_id' => $provider->id, 'hub_type' => LogisticsHub::PROVINCE,
            'name' => $name, 'region_code' => '030000000', 'region_name' => 'Region III',
            'province_code' => '030540000', 'province_name' => 'Pampanga',
            'city_municipality_code' => '030540100', 'municipality' => 'San Fernando',
            'barangay_code' => '0305401001', 'barangay_name' => 'Barangay 1',
            'street' => '123 Rizal Street', 'approval_status' => 'approved', 'is_active' => true,
        ]);
    }

    private function document(LogisticsProvider $provider, array $old = [], array $errors = []): \DOMXPath
    {
        $this->withoutVite();
        $this->actingAs($provider, 'logistics');
        if ($errors !== []) {
            if (isset($old['edit_hub_id'])) {
                $response = $this->from(route('logistics.hubs.index'))->patch(route('logistics.hubs.update', $old['edit_hub_id']), $old);
            } elseif (isset($old['parent_hub_id'])) {
                $response = $this->from(route('logistics.hubs.index'))->post(route('logistics.hubs.stations.store', $old['parent_hub_id']), $old);
            } elseif (isset($old['is_active'])) {
                $response = $this->from(route('logistics.hubs.index'))->patch(route('logistics.hubs.status', LogisticsHub::firstOrFail()), $old);
            } else {
                $this->fail('Unknown hub form state.');
            }
            $response->assertRedirect()->assertSessionHasErrors(array_keys($errors));
        }
        $response = $this->withCookie(config('session.cookie'), session()->getId())->get(route('logistics.hubs.index'))->assertOk();
        if ($errors !== []) {
            $this->assertTrue(str_contains($response->getContent(), 'Please review the following'), json_encode(['sessionErrors' => session('errors')?->all(), 'viewErrors' => View::shared('errors')?->all(), 'oldName' => old('name')]));
        }
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());

        return new \DOMXPath($document);
    }

    public function test_creation_and_edit_forms_are_collapsed_while_all_original_fields_remain_available(): void
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $province = $this->province($provider);
        $xpath = $this->document($provider);

        // Scope to the hubs page: the active "Team & network" sidebar group is
        // intentionally expanded and is not a hub form.
        $this->assertSame(0, $xpath->query('//*[@data-hubs-page]//details[@open]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-province-create]')->length, 'province self-service creation removed');
        $stationForm = $xpath->query('//*[@data-station-create]//form')->item(0);
        $this->assertSame(route('logistics.hubs.stations.store', $province), $stationForm->getAttribute('action'));
        foreach (['name', 'city_municipality_code', 'barangay_code', 'street', 'building', 'landmark', 'postal_code', 'location_details', 'parent_hub_id', '_token'] as $field) {
            $this->assertSame(1, $xpath->query('.//*[@name="'.$field.'"]', $stationForm)->length, $field);
        }
        $this->assertSame('030540000', $stationForm->getAttribute('data-locked-province-code'));
        $this->assertSame(1, $xpath->query('//a[@href="'.route('logistics.areas.index').'" and contains(., "Barangay coverage")]')->length);
    }

    public function test_province_hub_creation_is_no_longer_offered_or_routable(): void
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $this->province($provider);

        $xpath = $this->document($provider);

        $this->assertSame(0, $xpath->query('//*[@data-province-create]')->length);
        $this->assertSame(0, $xpath->query('//*[contains(., "Add Province Hub")]')->length);
        $this->assertSame(1, $xpath->query('//p[contains(normalize-space(.), "Service province")]')->length);

        $this->actingAs($provider, 'logistics')
            ->post('/logistics/hubs/province', ['name' => 'Rogue Province Hub'])
            ->assertStatus(405);
    }

    public function test_failed_station_creation_restores_only_the_matching_parent_form(): void
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        // Phase 2: only the live Province Hub can accept stations.
        $live = $this->province($provider);
        $xpath = $this->document($provider, [
            'parent_hub_id' => $live->id, 'name' => 'Attempted Station', 'street' => '', 'location_details' => 'Retained station notes',
            'city_municipality_code' => '030540100', 'barangay_code' => '0305401001',
        ], ['street' => 'The street address field is required.']);

        $this->assertSame(1, $xpath->query('//*[@data-station-create and @open]')->length);
        $this->assertSame(1, $xpath->query('//*[@data-station-create and @data-parent-hub-id="'.$live->id.'" and @open]')->length);
        $form = $xpath->query('//*[@data-station-create and @data-parent-hub-id="'.$live->id.'"]//form')->item(0);
        $this->assertSame('0305401001', $form->getAttribute('data-selected-barangay'));
        $this->assertSame('Attempted Station', $xpath->query('.//input[@name="name"]', $form)->item(0)->getAttribute('value'));
    }

    public function test_failed_station_edit_opens_its_details_and_edit_form_with_old_values(): void
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $province = $this->province($provider);
        $station = LogisticsHub::create([
            'logistics_provider_id' => $provider->id, 'parent_hub_id' => $province->id,
            'hub_type' => LogisticsHub::STATION, 'name' => 'Station', 'municipality' => 'San Fernando',
            'province_code' => $province->province_code, 'province_name' => $province->province_name, 'region_code' => $province->region_code,
            'city_municipality_code' => '030540100', 'barangay_code' => '0305401001',
            'street' => 'Old street', 'approval_status' => 'approved', 'is_active' => true,
        ]);
        $xpath = $this->document($provider, [
            'edit_hub_id' => $station->id, 'name' => 'Retained edit', 'street' => '',
            'city_municipality_code' => '030540200', 'barangay_code' => '0305402001',
        ], ['street' => 'The street address field is required.']);

        $this->assertSame(1, $xpath->query('//*[@data-station-details and @open]')->length);
        $edit = $xpath->query('//*[@data-hub-edit and @data-hub-id="'.$station->id.'" and @open]')->item(0);
        $this->assertNotNull($edit);
        $this->assertSame('Retained edit', $xpath->query('.//input[@name="name"]', $edit)->item(0)->getAttribute('value'));
        $this->assertSame('0305402001', $xpath->query('.//form', $edit)->item(0)->getAttribute('data-selected-barangay'));
    }

    public function test_status_errors_are_visible_without_opening_a_creation_form(): void
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $this->province($provider)->update(['approval_status' => 'pending', 'is_active' => false]);
        $xpath = $this->document($provider, ['is_active' => '1'], ['is_active' => 'Only approved hubs can be activated.']);

        $this->assertSame(1, $xpath->query('//*[@role="alert" and contains(., "Only approved hubs can be activated.")]')->length);
        $this->assertSame(0, $xpath->query('//*[@data-hubs-page]//details[@open]')->length);
    }
}
