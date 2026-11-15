<?php

namespace Tests\Feature\Buyer;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\DeliveryOtp;
use App\Models\Shared\SellerOrder;
use Tests\Feature\Rider\RiderTestCase;

/**
 * Buyer mobile shipment API (/api/buyer/shipments, confirm-received).
 * Reuses the rider fixture for a realistic buyer → order → seller-order graph.
 */
class BuyerShipmentApiTest extends RiderTestCase
{
    private function api(?Buyer $buyer = null): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $token = ($buyer ?? $this->buyer)->createToken('api-token', ['buyer'], now()->addHour())->plainTextToken;

        return $this->withToken($token)->withHeader('Accept', 'application/json');
    }

    private function strangerBuyer(): Buyer
    {
        return Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
    }

    public function test_shipments_are_scoped_to_the_token_owner(): void
    {
        $mine = $this->parcel(OrderStatus::OutForDelivery, ['delivery_courier_id' => $this->courier->id]);
        $this->deliveryOtp($mine);
        DeliveryOtp::query()->where('seller_order_id', $mine->id)->firstOrFail()
            ->forceFill(['code_encrypted' => '123456'])->save();

        $this->api()->getJson('/api/buyer/shipments')->assertOk()
            ->assertJsonPath('data.data.0.id', $mine->id)
            ->assertJsonMissingPath('data.data.0.commission_minor');

        // The buyer sees their own active delivery code, as on the web order page.
        $this->api()->getJson("/api/buyer/shipments/{$mine->id}")->assertOk()
            ->assertJsonPath('data.delivery_code.code', '123456');

        $stranger = $this->strangerBuyer();
        $this->api($stranger)->getJson('/api/buyer/shipments')->assertOk()->assertJsonCount(0, 'data.data');
        $this->api($stranger)->getJson("/api/buyer/shipments/{$mine->id}")->assertNotFound();
    }

    public function test_rider_tokens_cannot_read_buyer_shipments(): void
    {
        $token = $this->rider->createToken('api-token', ['rider'], now()->addHour())->plainTextToken;
        $this->withToken($token)->getJson('/api/buyer/shipments')->assertForbidden();
    }

    public function test_confirm_received_completes_only_my_delivered_shipment(): void
    {
        $parcel = $this->parcel(OrderStatus::Delivered, ['delivery_courier_id' => $this->courier->id]);

        $this->api($this->strangerBuyer())
            ->postJson("/api/buyer/orders/{$parcel->order_id}/shipments/{$parcel->id}/confirm-received")
            ->assertNotFound();
        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);

        $this->api()->postJson("/api/buyer/orders/{$parcel->order_id}/shipments/{$parcel->id}/confirm-received")
            ->assertOk()->assertJsonPath('data.status', OrderStatus::Completed->value)
            ->assertJsonPath('data.completed_at', $parcel->fresh()->completionTime()?->toIso8601String());
        $this->assertNotNull($parcel->fresh()->completionTime());

        // Idempotent repeat.
        $this->api()->postJson("/api/buyer/orders/{$parcel->order_id}/shipments/{$parcel->id}/confirm-received")->assertOk();
    }

    public function test_confirm_received_rejects_an_undelivered_shipment_without_change(): void
    {
        $parcel = $this->parcel(OrderStatus::OutForDelivery, ['delivery_courier_id' => $this->courier->id]);

        $this->api()->postJson("/api/buyer/orders/{$parcel->order_id}/shipments/{$parcel->id}/confirm-received")
            ->assertStatus(422);
        $this->assertSame(OrderStatus::OutForDelivery, SellerOrder::find($parcel->id)->status);
    }
}
