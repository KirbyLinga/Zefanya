<?php

namespace Tests\Feature\Logistics;

use App\Enums\ParcelHandoffStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelHandoff;
use App\Services\Logistics\ParcelHandoffService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

/** A destination acting on a handoff another screen already decided gets 409, and custody stays put. */
class HandoffStaleActionTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs;
    use LazilyRefreshDatabase;

    private LogisticsProvider $destination;

    private ParcelHandoff $handoff;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = Admin::factory()->create();
        $origin = $this->operationalHandoffProvider('Origin Logistics', '030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario')['provider'];
        $this->destination = $this->operationalHandoffProvider(
            'Destination Logistics', '137404000', 'Metro Manila', '137404000', 'Quezon City', '1374040001', 'Diliman', '130000000', 'National Capital Region',
        )['provider'];
        $seller = $this->sellerIn('030540000', 'Pampanga', '030540100', 'San Fernando', '0305401001', 'Santo Rosario');
        $buyer = $this->buyerIn('137404000', 'Metro Manila', '137404000', 'Quezon City', '1374040001', 'Diliman');
        $parcel = $this->handoffParcel($seller, $buyer, $origin, 'TRK-STALE-0001');
        $this->activeHandoffLink($origin, $this->destination, $admin);
        $this->handoff = app(ParcelHandoffService::class)->request($parcel, $origin);
    }

    private function assertConflict(callable $action): void
    {
        try {
            $action();
            $this->fail('A stale handoff action went through.');
        } catch (ValidationException $e) {
            $this->assertSame(409, $e->status);
        }
    }

    public function test_rejecting_a_handoff_already_accepted_from_another_screen_is_a_conflict(): void
    {
        $stale = $this->handoff->fresh();
        app(ParcelHandoffService::class)->accept($this->handoff, $this->destination);

        $this->assertConflict(fn () => app(ParcelHandoffService::class)->reject($stale, $this->destination, 'No capacity today.'));

        $fresh = $this->handoff->fresh();
        $this->assertSame(ParcelHandoffStatus::Accepted, $fresh->status);
        $this->assertNull($fresh->rejected_at);
        $this->assertNull($fresh->sellerOrder->custody_provider_id);
    }

    public function test_accepting_a_handoff_twice_is_a_conflict_and_keeps_the_first_acceptance(): void
    {
        $accepted = app(ParcelHandoffService::class)->accept($this->handoff, $this->destination);

        $this->assertConflict(fn () => app(ParcelHandoffService::class)->accept($this->handoff, $this->destination));

        $this->assertEquals($accepted->accepted_at, $this->handoff->fresh()->accepted_at);
    }

    public function test_receiving_a_handoff_that_was_never_dispatched_is_a_conflict(): void
    {
        app(ParcelHandoffService::class)->accept($this->handoff, $this->destination);

        $this->assertConflict(fn () => app(ParcelHandoffService::class)->receive($this->handoff, $this->destination, 'token'));

        $this->assertNull($this->handoff->fresh()->sellerOrder->custody_provider_id);
    }
}
