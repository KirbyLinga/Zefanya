<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class DeliveryAssignmentDialogTest extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    private function parcel(): SellerOrder
    {
        return SellerOrder::factory()->status(OrderStatus::Sorted)->create([
            'order_id' => Order::factory()->create(['shipping_address' => ['barangay_code' => '0305401001']])->id,
            'parcel_weight_grams' => 1000,
        ]);
    }

    private function dialog(SellerOrder $shipment, $couriers, ?string $networkBlock = null): string
    {
        return (string) $this->view('Logistics.DeliveryAssignments._assign-rider-dialog', [
            'shipment' => $shipment,
            'availableCouriers' => collect($couriers),
            'assignmentNetworkBlock' => $networkBlock,
            'buttonClass' => 'primary',
            'quietButton' => 'quiet',
        ]);
    }

    public function test_no_online_riders_blocks_automatic_and_manual_assignment(): void
    {
        $html = $this->dialog($this->parcel(), []);
        $this->assertStringContainsString('No approved, online Rider is available in your fleet.', $html);
        $this->assertStringContainsString('No online Rider is listed', $html);
        $this->assertStringContainsString(route('logistics.riders.index'), $html);
        $this->assertStringContainsString(route('logistics.areas.index'), $html);
        $this->assertMatchesRegularExpression('/<button type="submit"\s+disabled\b/', $html);
    }

    public function test_one_and_multiple_riders_offer_manual_and_automatic_choices(): void
    {
        $provider = LogisticsProvider::factory()->create();
        $first = $this->assignableCourier($provider, ['name' => 'First Rider']);
        $second = $this->assignableCourier($provider, ['name' => 'Second Rider']);
        $parcel = $this->parcel();
        $one = $this->dialog($parcel, [$first]);
        $this->assertStringContainsString('value="'.$first->id.'"', $one);
        $this->assertDoesNotMatchRegularExpression('/<button type="submit"\s+disabled\b/', $one);
        $many = $this->dialog($parcel, [$first, $second]);
        $this->assertStringContainsString('value="'.$first->id.'"', $many);
        $this->assertStringContainsString('value="'.$second->id.'"', $many);
        $this->assertStringContainsString('Auto-assign by area', $many);
    }

    public function test_no_capacity_or_network_setup_disables_submission_with_reason(): void
    {
        $provider = LogisticsProvider::factory()->create();
        $rider = $this->assignableCourier($provider, ['max_weight_grams' => 100]);
        $parcel = $this->parcel();
        $capacity = $this->dialog($parcel, [$rider]);
        $this->assertStringContainsString('Not enough remaining weight capacity.', $capacity);
        $this->assertMatchesRegularExpression('/<button type="submit"\s+disabled\b/', $capacity);
        $network = $this->dialog($parcel, [$rider], 'Complete network setup first.');
        $this->assertStringContainsString('Complete network setup first.', $network);
        $this->assertMatchesRegularExpression('/<button type="submit"\s+disabled\b/', $network);
    }

    public function test_missing_delivery_barangay_disables_assignment_and_offers_verification(): void
    {
        $provider = LogisticsProvider::factory()->create();
        $rider = $this->assignableCourier($provider);
        $parcel = $this->parcel();
        $parcel->order->forceFill(['shipping_address' => []])->save();
        $html = $this->dialog($parcel->fresh(), [$rider]);
        $this->assertStringContainsString('The delivery barangay is not verified', $html);
        $this->assertStringContainsString(route('logistics.parcel-sorting.address.preview', $parcel), $html);
        $this->assertMatchesRegularExpression('/<button type="submit"\s+disabled\b/', $html);
    }

    public function test_server_assignment_error_is_exposed_as_an_alert(): void
    {
        $provider = LogisticsProvider::factory()->create();
        $this->actingAs($provider, 'logistics')->withSession(['error' => 'Coverage changed; choose another Rider.'])
            ->get(route('logistics.delivery-assignments.index'))->assertOk()
            ->assertSee('id="assignment-error" tabindex="-1"', false)
            ->assertSee('data-flash-type="error" role="alert"', false)
            ->assertSee('Coverage changed; choose another Rider.');
    }

    public function test_no_manual_station_match_does_not_claim_automatic_is_unavailable(): void
    {
        $provider = LogisticsProvider::factory()->create();
        $rider = $this->assignableCourier($provider);
        $other = $this->assignableCourier(LogisticsProvider::factory()->create());
        $parcel = $this->parcel();
        $parcel->forceFill(['destination_area_id' => $other->area_id])->save();
        $html = $this->dialog($parcel->fresh(), [$rider]);
        $this->assertStringContainsString('No online Rider is listed', $html);
        $this->assertStringContainsString('Automatic assignment is checked', $html);
        $this->assertDoesNotMatchRegularExpression('/<button type="submit"\s+disabled\b/', $html);
    }
}
