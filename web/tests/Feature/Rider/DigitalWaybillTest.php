<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;

class DigitalWaybillTest extends RiderTestCase
{
    public function test_pickup_rider_sees_seller_contact_not_buyer_phone(): void
    {
        $this->withoutVite();

        $parcel = $this->parcel(OrderStatus::ReadyForPickup, [
            'courier_id' => $this->courier->id,
        ]);

        $this->seller->forceFill(['contact_no' => '09170001111', 'business_name' => 'QC Flowers'])->save();

        $response = $this->actingAsRider()
            ->get(route('rider.waybills.show', $parcel))
            ->assertOk()
            ->assertSee('QC Flowers', false)
            ->assertSee('Call seller', false)
            ->assertDontSee('Call buyer', false);

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringNotContainsString($this->buyer->contact_no, $response->getContent());
    }

    public function test_delivery_rider_sees_buyer_contact_and_call_link(): void
    {
        $this->withoutVite();

        $parcel = $this->parcel(OrderStatus::OutForDelivery, [], [
            'delivery_courier_id' => $this->courier->id,
            'courier_id' => $this->courier->id,
        ]);

        Payment::factory()->create([
            'seller_order_id' => $parcel->id,
            'method' => PaymentMethod::Cod,
            'status' => PaymentStatus::Pending,
            'amount_minor' => 150000,
        ]);

        $this->actingAsRider()
            ->get(route('rider.waybills.show', $parcel))
            ->assertOk()
            ->assertSee($this->buyer->fullName(), false)
            ->assertSee('123 Mabini Street', false)
            ->assertSee('Call buyer', false)
            ->assertSee('tel:', false)
            ->assertSee('Cash on Delivery', false)
            ->assertSee('Collect', false);
    }

    public function test_gcash_label_reflects_supported_method_without_account_ids(): void
    {
        $this->withoutVite();

        $parcel = $this->parcel(OrderStatus::OutForDelivery, [], [
            'delivery_courier_id' => $this->courier->id,
        ]);

        Payment::factory()->create([
            'seller_order_id' => $parcel->id,
            'method' => PaymentMethod::Gcash,
            'status' => PaymentStatus::Pending,
            'amount_minor' => 9900,
        ]);

        $this->actingAsRider()
            ->get(route('rider.waybills.show', $parcel))
            ->assertOk()
            ->assertSee('GCash', false)
            ->assertDontSee('gcash account', false)
            ->assertDontSee('Collect ₱', false);
    }

    public function test_unauthorized_rider_cannot_open_digital_waybill(): void
    {
        $other = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $parcel = $this->parcel(OrderStatus::OutForDelivery, [], [
            'delivery_courier_id' => $other->id,
        ]);

        $this->actingAsRider()
            ->get(route('rider.waybills.show', $parcel))
            ->assertForbidden();
    }

    public function test_guest_cannot_open_digital_waybill(): void
    {
        $parcel = $this->parcel(OrderStatus::ReadyForPickup, [
            'courier_id' => $this->courier->id,
        ]);

        $this->get(route('rider.waybills.show', $parcel))->assertRedirect();
    }

    public function test_delivered_parcel_waybill_is_no_longer_accessible(): void
    {
        $parcel = $this->parcel(OrderStatus::Delivered, [], [
            'delivery_courier_id' => $this->courier->id,
        ]);

        $this->actingAsRider()
            ->get(route('rider.waybills.show', $parcel))
            ->assertForbidden();
    }
}
