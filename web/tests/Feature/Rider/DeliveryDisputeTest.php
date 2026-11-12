<?php

namespace Tests\Feature\Rider;

use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\DeliveryDispute;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Services\Buyer\AutomaticBuyerReceiptService;
use App\Services\Buyer\DeliveryDisputeService;
use Illuminate\Http\UploadedFile;

class DeliveryDisputeTest extends RiderTestCase
{
    private function delivered(OrderStatus $status = OrderStatus::Delivered, int $days = 4): SellerOrder
    {
        $shipment = $this->parcel($status, ['delivery_courier_id' => $this->courier->id]);
        (new StatusHistory)->forceFill([
            'seller_order_id' => $shipment->id, 'to_status' => OrderStatus::Delivered,
            'from_status' => OrderStatus::OutForDelivery, 'actor_type' => 'rider',
            'source' => 'web', 'created_at' => now()->subDays($days),
        ])->save();

        return $shipment;
    }

    public function test_completed_buyer_can_report_once_within_delivery_window(): void
    {
        $shipment = $this->delivered(OrderStatus::Completed);
        $token = $this->buyer->createToken('buyer', ['buyer'])->plainTextToken;
        $url = route('api.buyer.shipments.dispute.show', $shipment);
        $this->withToken($token)->getJson($url)->assertOk()->assertJsonPath('data.can_report', true);
        $payload = ['explanation' => 'The parcel never arrived.'];
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.case.status', 'open');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.case.id', DeliveryDispute::sole()->id);
        $this->assertSame(OrderStatus::Completed, $shipment->fresh()->status);
        $this->assertDatabaseCount('parcel_returns', 0);
        $this->assertDatabaseCount('payment_cancellation_refunds', 0);
    }

    public function test_window_and_buyer_ownership_are_enforced(): void
    {
        $shipment = $this->delivered(days: 8);
        $url = route('api.buyer.shipments.dispute.show', $shipment);
        $other = Buyer::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->withToken($other->createToken('other', ['buyer'])->plainTextToken)->getJson($url)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->buyer->createToken('buyer', ['buyer'])->plainTextToken)
            ->postJson($url, ['explanation' => 'Parcel missing.'])->assertUnprocessable();
        $this->assertDatabaseCount('delivery_disputes', 0);
    }

    public function test_open_dispute_blocks_receipt_and_denial_releases_only_its_hold(): void
    {
        $shipment = $this->delivered();
        $other = $this->delivered();
        $service = app(DeliveryDisputeService::class);
        $case = $service->open($shipment, $this->buyer, 'I did not receive my parcel.');
        $this->travel(4)->days();
        $cutoff = now()->subHours(AutomaticBuyerReceiptService::WAIT_HOURS);
        $receipts = app(AutomaticBuyerReceiptService::class);
        $this->assertFalse($receipts->complete($shipment->id, $cutoff));
        $this->assertTrue($receipts->complete($other->id, $cutoff));
        $this->assertTrue(DeliveryDisputeService::holdsSettlement($shipment));

        $this->actingAs(LogisticsProvider::factory()->create(['status' => 'approved']), 'logistics')
            ->post(route('logistics.delivery-disputes.review', $case), [
                'action' => 'denied', 'note' => 'Not the investigating provider.', 'investigator' => 'Other',
            ])->assertForbidden();
        $service->review($case, $this->provider, 'escalate', 'Handover could not be independently verified.', 'Investigator A');
        $admin = Admin::factory()->create(['role' => 'admin']);
        $service->review($case, $admin, 'denied', 'Verified later with the Buyer.');
        $this->assertFalse(DeliveryDisputeService::holdsSettlement($shipment));
        $this->assertTrue($receipts->complete($shipment->id, $cutoff));
    }

    public function test_upheld_claim_keeps_hold_until_admin_records_financial_review(): void
    {
        $shipment = $this->delivered();
        $service = app(DeliveryDisputeService::class);
        $case = $service->open($shipment, $this->buyer, 'Parcel missing.');
        $service->review($case, $this->provider, 'upheld', 'Handover was recorded against the wrong parcel.', 'Investigator A');
        $this->assertTrue(DeliveryDisputeService::holdsSettlement($shipment));
        $service->review($case, Admin::factory()->create(['role' => 'admin']), 'release_hold', 'Finance review recorded separately.');
        $this->assertFalse(DeliveryDisputeService::holdsSettlement($shipment));
        $this->assertSame('upheld', $case->fresh()->outcome);
        $this->assertDatabaseHas('operations_audit_logs', ['action' => 'delivery_dispute_finance_reviewed']);
    }

    public function test_investigation_evidence_is_private_to_the_assigned_provider_and_admin(): void
    {
        $this->withoutVite();
        $shipment = $this->delivered();
        $this->actingAs($this->buyer, 'buyer')->post(route('buyer.shipments.dispute.store', $shipment), [
            'explanation' => 'Nobody received the parcel at my door.',
            'evidence' => UploadedFile::fake()->image('door.jpg'),
        ])->assertSessionHasNoErrors();
        $case = DeliveryDispute::sole();
        $url = route('logistics.delivery-disputes.evidence', $case);
        $this->actingAs(LogisticsProvider::factory()->create(['status' => 'approved']), 'logistics')->get($url)->assertNotFound();
        $this->actingAs($this->provider, 'logistics')->get($url)->assertOk();
        $this->get(route('logistics.delivery-disputes.index'))->assertOk()->assertSee('Case #'.$case->id);
        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')
            ->get(route('admin.delivery-disputes.evidence', $case))->assertOk();
    }
}
