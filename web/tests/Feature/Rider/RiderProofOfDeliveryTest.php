<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Logistics\DeliveryProof;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Notifications\Logistics\RiderParcelActivity;
use App\Services\Logistics\ParcelHandoffVerification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

class RiderProofOfDeliveryTest extends RiderTestCase
{
    private function photo(string $name = 'proof.jpg'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 120, 'image/jpeg');
    }

    public function test_proof_photo_is_required(): void
    {
        $parcel = $this->outForDelivery();

        $this->actingAsRider()
            ->post(route('rider.deliveries.deliver', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, []))
            ->assertSessionHasErrors('proof_photo');

        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);
        $this->assertSame(0, DeliveryProof::query()->count());
    }

    public function test_a_non_image_upload_is_rejected(): void
    {
        $parcel = $this->outForDelivery();

        $this->actingAsRider()
            ->post(route('rider.deliveries.deliver', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
                'proof_photo' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('proof_photo');

        $this->assertSame(0, DeliveryProof::query()->count());
    }

    public function test_delivery_saves_private_proof_and_records_the_audit_trail(): void
    {
        $parcel = $this->outForDelivery();

        $this->actingAsRider()
            ->post(route('rider.deliveries.deliver', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
                'proof_photo' => $this->photo(),
                'received_by' => 'Maria Buyer',
                'delivery_notes' => 'Handed to the recipient at the gate.',
            ]))
            ->assertRedirect(route('rider.deliveries.index'))
            ->assertSessionHas('success');

        $parcel = $parcel->fresh();

        $this->assertSame(OrderStatus::Delivered, $parcel->status);

        $proof = DeliveryProof::query()->where('seller_order_id', $parcel->id)->firstOrFail();

        $this->assertSame($this->courier->id, (int) $proof->courier_id);
        $this->assertSame($this->rider->id, (int) $proof->rider_id);
        $this->assertSame('Maria Buyer', $proof->received_by);
        $this->assertNotNull($proof->submitted_at);

        Storage::disk('local')->assertExists($proof->photo_path);
        // Private disk: the evidence must never land on the public disk.
        $this->assertFalse(Storage::disk('public')->exists($proof->photo_path));

        $history = StatusHistory::query()
            ->where('seller_order_id', $parcel->id)
            ->where('to_status', OrderStatus::Delivered->value)
            ->first();

        $this->assertNotNull($history);
        $this->assertSame($this->courier->id, (int) $history->actor_id);

        Notification::assertSentTo(
            $this->provider,
            RiderParcelActivity::class,
            fn (RiderParcelActivity $notification): bool => $notification->event === RiderParcelActivity::DELIVERED,
        );
    }

    public function test_duplicate_delivery_confirmation_is_idempotent(): void
    {
        $parcel = $this->outForDelivery();

        $payload = [
            'proof_photo' => $this->photo(),
            'received_by' => 'Maria Buyer',
        ];

        $this->actingAsRider()->post(route('rider.deliveries.deliver', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, $payload))->assertRedirect();
        $this->actingAsRider()->post(route('rider.deliveries.deliver', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, $payload))->assertRedirect();

        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
        $this->assertSame(1, DeliveryProof::query()->where('seller_order_id', $parcel->id)->count());
        $this->assertSame(
            1,
            StatusHistory::query()
                ->where('seller_order_id', $parcel->id)
                ->where('to_status', OrderStatus::Delivered->value)
                ->count(),
        );
    }

    public function test_cod_is_collected_exactly_once_on_successful_delivery(): void
    {
        $parcel = $this->outForDelivery(['amount_minor' => 150000]);

        $this->actingAsRider()
            ->post(route('rider.deliveries.deliver', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
                'proof_photo' => $this->photo(),
                'cod_amount' => '1500.00',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $payment = Payment::query()->where('seller_order_id', $parcel->id)->firstOrFail();

        $this->assertSame(PaymentStatus::Paid, $payment->status);
        $this->assertSame(150000, (int) $payment->collected_amount_minor);
        $this->assertSame('rider', $payment->collected_by_type);
        $this->assertSame($this->courier->id, (int) $payment->collected_by_id);
        $this->assertNotNull($payment->collected_at);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_a_mismatched_cod_amount_rolls_back_the_whole_delivery(): void
    {
        $parcel = $this->outForDelivery(['amount_minor' => 150000]);
        $payload = $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
            'proof_photo' => $this->photo(),
            'cod_amount' => '1400.00',
        ]);
        $token = $payload['verification_token'];

        $this->actingAsRider()
            ->post(route('rider.deliveries.deliver', $parcel), $payload)
            ->assertSessionHasErrors('cod_amount_minor');

        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);
        $this->assertSame(0, DeliveryProof::query()->count());

        $payment = Payment::query()->where('seller_order_id', $parcel->id)->firstOrFail();
        $this->assertSame(PaymentStatus::Pending, $payment->status);
        $this->assertNull($payment->collected_by_id);

        // Invalid COD must not burn the scan token — retry with the same token.
        $this->actingAsRider()
            ->post(route('rider.deliveries.deliver', $parcel), [
                'verification_token' => $token,
                'delivery_otp' => $this->deliveryOtp($parcel),
                'proof_photo' => $this->photo(),
                'cod_amount' => '1500.00',
            ])
            ->assertRedirect(route('rider.deliveries.index'))
            ->assertSessionHas('success');

        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
    }

    public function test_cod_requires_the_amount_to_be_entered(): void
    {
        $parcel = $this->outForDelivery(['amount_minor' => 99900]);

        $this->actingAsRider()
            ->post(route('rider.deliveries.deliver', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
                'proof_photo' => $this->photo(),
            ]))
            ->assertSessionHasErrors('cod_amount_minor');

        $this->assertSame(OrderStatus::OutForDelivery, $parcel->fresh()->status);
    }

    public function test_already_collected_cod_does_not_require_collection_again(): void
    {
        $parcel = $this->outForDelivery([
            'amount_minor' => 150000,
            'method' => 'cod',
            'status' => PaymentStatus::Paid->value,
            'paid_at' => now(),
        ]);

        $this->actingAsRider()
            ->post(route('rider.deliveries.deliver', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
                'proof_photo' => $this->photo(),
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(OrderStatus::Delivered, $parcel->fresh()->status);
    }

    public function test_delivery_is_rejected_when_the_parcel_is_not_out_for_delivery(): void
    {
        $parcel = $this->parcel(OrderStatus::AssignedToRider, [
            'delivery_courier_id' => $this->courier->id,
        ]);

        $this->actingAsRider()
            ->post(route('rider.deliveries.deliver', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
                'proof_photo' => $this->photo(),
            ]))
            ->assertSessionHasErrors('parcel');

        $this->assertSame(OrderStatus::AssignedToRider, $parcel->fresh()->status);
        $this->assertSame(0, DeliveryProof::query()->count());
    }

    public function test_a_rider_cannot_deliver_another_riders_parcel(): void
    {
        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');

        $parcel = $this->parcel(OrderStatus::OutForDelivery, [
            'delivery_courier_id' => $otherCourier->id,
        ]);

        $this->actingAsRider()
            ->post(route('rider.deliveries.deliver', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
                'proof_photo' => $this->photo(),
            ]))
            ->assertForbidden();

        $this->assertSame(0, DeliveryProof::query()->count());
    }

    public function test_proof_evidence_is_only_streamed_to_authorized_roles(): void
    {
        $parcel = $this->outForDelivery();

        $this->actingAsRider()->post(route('rider.deliveries.deliver', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
            'proof_photo' => $this->photo(),
        ]))->assertRedirect();

        $proof = DeliveryProof::query()->where('seller_order_id', $parcel->id)->firstOrFail();

        // The rider who submitted it can view it.
        $this->actingAsRider()->get(route('rider.proofs.show', $proof))->assertOk();

        // Another rider on the same company cannot.
        $otherCourier = $this->assignableCourier($this->provider, [], 'Quezon City', '137404000');
        $this->actingAsRider($otherCourier->rider->fresh())
            ->get(route('rider.proofs.show', $proof))
            ->assertForbidden();

        // The handling Logistics company can.
        $this->actingAs($this->provider, 'logistics')
            ->get(route('logistics.proofs.show', $proof))
            ->assertOk();

        // Any other Logistics company gets a 404 (existence is not leaked).
        $otherProvider = LogisticsProvider::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($otherProvider, 'logistics')
            ->get(route('logistics.proofs.show', $proof))
            ->assertNotFound();
    }

    public function test_delivery_rolls_the_parent_order_status_up(): void
    {
        $parcel = $this->outForDelivery();

        $this->actingAsRider()->post(route('rider.deliveries.deliver', $parcel), $this->withHandoff($parcel, ParcelHandoffVerification::ACTION_COMPLETE_DELIVERY, [
            'proof_photo' => $this->photo(),
        ]))->assertRedirect();

        $this->assertSame(OrderStatus::Delivered, $parcel->order->fresh()->status);
    }

    /**
     * Seed an OutForDelivery parcel for this rider, with an optional payment.
     *
     * @param  array<string, mixed>  $payment
     */
    private function outForDelivery(array $payment = []): SellerOrder
    {
        $parcel = $this->parcel(
            OrderStatus::OutForDelivery,
            ['delivery_courier_id' => $this->courier->id],
            ['destination_area_id' => $this->courier->area_id],
        );

        if ($payment !== []) {
            Payment::factory()->create(array_merge([
                'seller_order_id' => $parcel->id,
                'amount_minor' => 150000,
            ], $payment));
        }

        return $parcel;
    }
}
