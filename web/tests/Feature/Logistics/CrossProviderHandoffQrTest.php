<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Enums\ParcelHandoffStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelHandoff;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Services\ParcelHandoffService;
use App\Services\ParcelHandoffVerification;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class CrossProviderHandoffQrTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use LazilyRefreshDatabase;

    private Admin $admin;

    private LogisticsProvider $origin;

    private LogisticsProvider $destination;

    private LogisticsProvider $outsider;

    private Seller $seller;

    private Buyer $buyer;

    private SellerOrder $parcel;

    private ParcelHandoff $handoff;

    private ParcelHandoffService $handoffs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::factory()->create();
        $this->origin = $this->operationalHandoffProvider(
            'Origin Logistics',
            '030540000',
            'Pampanga',
            '030540100',
            'San Fernando',
            '0305401001',
            'Santo Rosario',
        )['provider'];
        $this->destination = $this->operationalHandoffProvider(
            'Destination Logistics',
            '137404000',
            'Metro Manila',
            '137404000',
            'Quezon City',
            '1374040001',
            'Diliman',
            '130000000',
            'National Capital Region',
        )['provider'];
        $this->outsider = $this->operationalHandoffProvider(
            'Outsider Logistics',
            '030540000',
            'Pampanga',
            '030540100',
            'San Fernando',
            '0305401002',
            'Second Barangay',
        )['provider'];

        $this->seller = $this->sellerIn('030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');
        $this->buyer = $this->buyerIn('137404000', 'Metro Manila', '137404000', 'Quezon City', '1374040001', 'Diliman');
        $this->parcel = $this->handoffParcel($this->seller, $this->buyer, $this->origin, 'TRK-PHASE54-0001');
        $this->handoffs = app(ParcelHandoffService::class);

        $this->activeHandoffLink($this->origin, $this->destination, $this->admin);
        $this->handoff = $this->handoffs->request($this->parcel, $this->origin);
        $this->handoffs->accept($this->handoff, $this->destination);
        $this->handoff->refresh();
    }

    public function test_only_the_origin_can_dispatch_an_accepted_handoff(): void
    {
        $this->expectException(HttpException::class);
        $this->handoffs->dispatch($this->handoff, $this->destination, 'forged-token');
    }

    public function test_dispatch_requires_a_valid_single_use_scan_and_does_not_transfer_custody(): void
    {
        $verification = $this->handoffs->verifyScan(
            $this->handoff,
            $this->origin,
            'TRK-PHASE54-0001',
            ParcelHandoffService::PHASE_DISPATCH,
        );

        $this->assertNotEmpty($verification['verification_token']);
        $this->assertSame('handoff_dispatch', $verification['context']);

        $dispatched = $this->handoffs->dispatch(
            $this->handoff,
            $this->origin,
            $verification['verification_token'],
        );

        $parcel = $this->parcel->fresh();
        $this->assertSame(ParcelHandoffStatus::InTransit, $dispatched->status);
        $this->assertNotNull($dispatched->dispatched_at);
        $this->assertSame($this->origin->id, (int) $dispatched->dispatched_by_id);
        $this->assertNull($parcel->custody_provider_id);
        $this->assertSame($this->origin->id, (int) $parcel->carrier_provider_id);
        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->status);
        $this->assertSame('TRK-PHASE54-0001', $parcel->tracking_number);
    }

    public function test_forged_tracking_numbers_and_tokens_are_rejected(): void
    {
        try {
            $this->handoffs->verifyScan($this->handoff, $this->origin, 'TRK-WRONG', ParcelHandoffService::PHASE_DISPATCH);
            $this->fail('A wrong tracking number passed verification.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tracking_number', $exception->errors());
        }

        try {
            $this->handoffs->dispatch($this->handoff, $this->origin, 'forged-token');
            $this->fail('A forged scan token passed dispatch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('verification_token', $exception->errors());
        }

        $this->assertSame(ParcelHandoffStatus::Accepted, $this->handoff->fresh()->status);
        $this->assertNull($this->parcel->fresh()->custody_provider_id);
    }

    public function test_expired_scan_tokens_are_rejected(): void
    {
        $verification = $this->handoffs->verifyScan(
            $this->handoff,
            $this->origin,
            'TRK-PHASE54-0001',
            ParcelHandoffService::PHASE_DISPATCH,
        );

        $this->travel(11)->minutes();

        try {
            $this->handoffs->dispatch($this->handoff, $this->origin, $verification['verification_token']);
            $this->fail('An expired scan token passed dispatch.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('verification_token', $exception->errors());
        }

        $this->assertSame(ParcelHandoffStatus::Accepted, $this->handoff->fresh()->status);
    }

    public function test_dispatch_token_cannot_be_replayed_on_receiving_side(): void
    {
        $dispatchVerification = $this->handoffs->verifyScan(
            $this->handoff,
            $this->origin,
            'TRK-PHASE54-0001',
            ParcelHandoffService::PHASE_DISPATCH,
        );
        $this->handoffs->dispatch($this->handoff, $this->origin, $dispatchVerification['verification_token']);
        $this->handoff->refresh();

        $this->expectException(ValidationException::class);
        $this->handoffs->receive($this->handoff, $this->destination, $dispatchVerification['verification_token']);
    }

    public function test_only_destination_verified_receipt_transfers_custody_and_preserves_status(): void
    {
        $this->dispatchParcel();

        $verification = $this->handoffs->verifyScan(
            $this->handoff->fresh(),
            $this->destination,
            'TRK-PHASE54-0001',
            ParcelHandoffService::PHASE_RECEIVE,
        );

        $received = $this->handoffs->receive(
            $this->handoff->fresh(),
            $this->destination,
            $verification['verification_token'],
        );

        $parcel = $this->parcel->fresh();
        $this->assertSame(ParcelHandoffStatus::Received, $received->status);
        $this->assertNotNull($received->received_at);
        $this->assertSame($this->destination->id, (int) $received->received_by_id);
        $this->assertSame($this->destination->id, (int) $parcel->custody_provider_id);
        $this->assertSame($this->origin->id, (int) $parcel->carrier_provider_id);
        $this->assertSame((int) $received->receiving_hub_id, (int) $parcel->custody_hub_id);
        $this->assertSame(OrderStatus::AtSortingCenter, $parcel->status);
        $this->assertSame('TRK-PHASE54-0001', $parcel->tracking_number);
    }

    public function test_replayed_receiving_token_cannot_transfer_custody_twice(): void
    {
        $this->dispatchParcel();

        $verification = $this->handoffs->verifyScan(
            $this->handoff->fresh(),
            $this->destination,
            'TRK-PHASE54-0001',
            ParcelHandoffService::PHASE_RECEIVE,
        );
        $this->handoffs->receive($this->handoff->fresh(), $this->destination, $verification['verification_token']);

        try {
            $this->handoffs->receive($this->handoff->fresh(), $this->destination, $verification['verification_token']);
            $this->fail('A receiving token was replayed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(ParcelHandoffStatus::Received, $this->handoff->fresh()->status);
        $this->assertSame($this->destination->id, (int) $this->parcel->fresh()->custody_provider_id);
    }

    public function test_wrong_hub_binding_is_rejected_even_with_a_valid_token(): void
    {
        $this->dispatchParcel();

        $otherStation = LogisticsHub::query()->create([
            'logistics_provider_id' => $this->destination->id,
            'parent_hub_id' => $this->handoff->fresh()->receivingHub->parent_hub_id,
            'name' => 'Unapproved token station',
            'hub_type' => LogisticsHub::STATION,
            'region_code' => '130000000',
            'region_name' => 'National Capital Region',
            'province_code' => '137404000',
            'province_name' => 'Metro Manila',
            'city_municipality_code' => '137404000',
            'municipality' => 'Quezon City',
            'barangay_code' => '1374040001',
            'barangay_name' => 'Diliman',
            'street' => '2 Other Street',
            'is_active' => true,
            'approval_status' => LogisticsHub::APPROVAL_APPROVED,
        ]);

        $token = app(ParcelHandoffVerification::class)->issueForHandoff(
            $this->destination,
            $this->handoff->fresh(),
            ParcelHandoffVerification::ACTION_HANDOFF_RECEIVE,
            (int) $otherStation->id,
        );

        try {
            $this->handoffs->receive($this->handoff->fresh(), $this->destination, $token);
            $this->fail('A token issued for a different hub passed receiving.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('verification_token', $exception->errors());
        }

        $this->assertSame(ParcelHandoffStatus::InTransit, $this->handoff->fresh()->status);
        $this->assertNull($this->parcel->fresh()->custody_provider_id);
    }

    public function test_receiving_scan_is_bound_to_the_destination_provider(): void
    {
        $this->dispatchParcel();

        $this->expectException(HttpException::class);
        $this->handoffs->receive($this->handoff->fresh(), $this->outsider, 'forged-token');
    }

    public function test_verification_token_is_bound_to_one_handoff(): void
    {
        $secondParcel = $this->handoffParcel($this->seller, $this->buyer, $this->origin, 'TRK-PHASE54-0002');
        $secondHandoff = $this->handoffs->request($secondParcel, $this->origin);
        $this->handoffs->accept($secondHandoff, $this->destination);

        $verification = $this->handoffs->verifyScan(
            $this->handoff,
            $this->origin,
            'TRK-PHASE54-0001',
            ParcelHandoffService::PHASE_DISPATCH,
        );

        try {
            $this->handoffs->dispatch(
                $secondHandoff->fresh(),
                $this->origin,
                $verification['verification_token'],
            );
            $this->fail('A token issued for one handoff dispatched another handoff.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('verification_token', $exception->errors());
        }

        $this->assertSame(ParcelHandoffStatus::Accepted, $secondHandoff->fresh()->status);
        $this->assertSame(ParcelHandoffStatus::Accepted, $this->handoff->fresh()->status);
    }

    private function dispatchParcel(): void
    {
        $verification = $this->handoffs->verifyScan(
            $this->handoff,
            $this->origin,
            'TRK-PHASE54-0001',
            ParcelHandoffService::PHASE_DISPATCH,
        );
        $this->handoffs->dispatch($this->handoff, $this->origin, $verification['verification_token']);
        $this->handoff->refresh();
    }
}
