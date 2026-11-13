<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\DeliveryProof;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\ParcelHandoffVerification;
use Illuminate\Http\UploadedFile;

class DeliveryAcknowledgementTest extends RiderTestCase
{
    private function payload(SellerOrder $parcel): array
    {
        return $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
            'proof_photo' => UploadedFile::fake()->image('proof.jpg'),
        ]);
    }

    public function test_authorized_representative_acknowledgement_is_recorded_with_private_proof(): void
    {
        $parcel = $this->parcel(OrderStatus::OutForDelivery, ['delivery_courier_id' => $this->courier->id]);
        $payload = array_replace($this->payload($parcel), [
            'recipient_category' => 'representative',
            'received_by' => 'Maria Cruz',
            'recipient_relationship' => 'Parent',
        ]);
        $this->actingAsRider()->get(route('rider.deliveries.proof', [$parcel, 'verification_token' => $payload['verification_token']]))
            ->assertOk()->assertSee('Authorized representative')->assertDontSee('name="delivery_otp"', false);
        $this->post(route('rider.deliveries.deliver', $parcel), $payload)
            ->assertRedirect(route('rider.deliveries.index'))->assertSessionHasNoErrors();
        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
        $proof = DeliveryProof::query()->sole();
        $this->assertSame('Maria Cruz', $proof->received_by);
        $acknowledgement = $proof->recipient_acknowledgement;
        ksort($acknowledgement);
        $this->assertSame([
            'category' => 'representative',
            'cod_collected' => false,
            'physical_handover' => true,
            'recipient_accepted' => true,
            'relationship' => 'Parent',
        ], $acknowledgement);
    }

    public function test_missing_evidence_and_cod_do_not_complete_delivery(): void
    {
        $parcel = $this->parcel(OrderStatus::OutForDelivery, ['delivery_courier_id' => $this->courier->id]);
        Payment::factory()->create(['seller_order_id' => $parcel->id, 'amount_minor' => 10000]);
        $this->actingAsRider();
        $url = route('rider.deliveries.deliver', $parcel);
        $payload = $this->payload($parcel);
        $this->post($url, array_diff_key($payload, array_flip(['proof_photo', 'recipient_accepted', 'physical_handover'])))
            ->assertSessionHasErrors(['proof_photo', 'recipient_accepted', 'physical_handover']);
        $this->post($url, array_replace($payload, ['recipient_category' => 'representative']))
            ->assertSessionHasErrors(['received_by', 'recipient_relationship']);
        $this->post($url, $payload)->assertSessionHasErrors('cod_amount_minor');
        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);
        $this->assertDatabaseCount('delivery_proofs', 0);
        $this->assertDatabaseCount('cod_remittances', 0);
    }

    public function test_only_buyer_can_read_order_and_no_delivery_code_is_shown(): void
    {
        $this->withoutVite();
        $parcel = $this->parcel(OrderStatus::OutForDelivery, ['delivery_courier_id' => $this->courier->id]);
        $page = route('buyer.orders.show', $parcel->order);
        $this->actingAs($this->buyer, 'buyer')->get($page)->assertOk()
            ->assertSee('someone you authorize')->assertDontSee('Delivery code');
        $stranger = Buyer::factory()->create();
        $response = $this->actingAs($stranger, 'buyer')->get($page);
        $this->assertContains($response->getStatusCode(), [403, 404]);
    }
}
