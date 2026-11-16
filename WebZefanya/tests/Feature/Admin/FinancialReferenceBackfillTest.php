<?php

namespace Tests\Feature\Admin;

use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\RiderPayout;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\FinancialReference;
use App\Services\Shared\FinancialReferenceRegistry;
use App\Support\FinancialReferenceHistory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * References recorded before the registry existed are protected after the
 * backfill: live ones block every reuse, reversed ones allow only the verified
 * correction (same type, recipient and amount).
 */
class FinancialReferenceBackfillTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function runBackfill(): void
    {
        FinancialReferenceHistory::backfill();
    }

    /** @param class-string<Model> $class */
    private function source(string $class, int $id): Model
    {
        return (new $class)->forceFill([(new $class)->getKeyName() => $id]);
    }

    private function seedHistory(): void
    {
        // Inside the test transaction: checked only at commit, which a refreshed test never reaches.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        DB::table('seller_payout_requests')->insert(['id' => 1, 'seller_id' => 5, 'idempotency_key' => 'k1', 'amount_minor' => 58410,
            'status' => 'PAID', 'payout_reference' => 'BDO 777', 'paid_at' => now()]);
        DB::table('rider_payouts')->insert([
            ['id' => 1, 'logistics_provider_id' => 3, 'courier_id' => 9, 'earning_count' => 2, 'amount_minor' => 6000, 'reference' => 'GC-1', 'paid_on' => today()],
            ['id' => 2, 'logistics_provider_id' => 3, 'courier_id' => 9, 'earning_count' => 1, 'amount_minor' => 3000, 'reference' => 'GC-2', 'paid_on' => today()],
        ]);
        DB::table('operations_audit_logs')->insert(['actor_type' => 'logistics', 'actor_id' => 3, 'actor_name' => 'Carrier',
            'action' => 'rider_payout_reversed', 'entity_type' => 'rider_payout', 'entity_id' => 2, 'entity_label' => 'Rider payout #2']);
        DB::table('parcel_returns')->insert(['id' => 4, 'seller_order_id' => 40, 'reason' => 'Damaged', 'requested_at' => now(),
            'refunded_at' => now(), 'refund_reference' => 'TXN-4', 'refund_amount_minor' => 10000]);
        DB::table('parcel_return_events')->insert(['parcel_return_id' => 4, 'event_key' => 'refund_voided', 'actor_type' => 'admin', 'occurred_at' => now(),
            'metadata' => json_encode(['reference' => 'TXN-OLD', 'amount_minor' => 10000])]);
    }

    public function test_history_is_recorded_and_live_references_block_any_reuse(): void
    {
        $this->seedHistory();
        $this->runBackfill();

        $this->assertSame(5, FinancialReference::query()->count());
        $this->assertSame('seller:5', FinancialReference::query()->where('normalized', 'BDO777')->value('recipient'));
        $this->assertNotNull(FinancialReference::query()->where('normalized', 'GC-2')->value('released_at'), 'A reversed rider payout is marked reversed.');
        $this->assertNull(FinancialReference::query()->where('normalized', 'GC-1')->value('released_at'));

        $registry = app(FinancialReferenceRegistry::class);
        foreach ([['payout', 'bdo777', RiderPayout::class, 'courier:9', 58410], ['payout', 'GC-1', RiderPayout::class, 'courier:9', 6000], ['refund', 'txn-4', ParcelReturn::class, 'return:5', 10000]] as [$scope, $ref, $class, $recipient, $amount]) {
            try {
                $registry->claim($scope, $ref, $this->source($class, 99), $recipient, $amount);
                $this->fail("Historical reference {$ref} was reused.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_historical_reversed_reference_allows_only_its_verified_correction(): void
    {
        $this->seedHistory();
        $this->runBackfill();
        $registry = app(FinancialReferenceRegistry::class);

        try {
            $registry->claim('payout', 'GC-2', $this->source(RiderPayout::class, 3), 'courier:10', 3000);
            $this->fail('A reversed historical reference was reused for another rider.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $registry->claim('payout', 'GC-2', $this->source(RiderPayout::class, 3), 'courier:9', 3000);
        $this->assertSame(1, FinancialReference::query()->whereNotNull('reuses_id')->count());
    }

    public function test_a_historical_voided_refund_reference_needs_evidence_unless_the_destination_matches(): void
    {
        $this->seedHistory();
        DB::table('parcel_return_events')->insert(['parcel_return_id' => 6, 'event_key' => 'refund_voided_2', 'actor_type' => 'admin', 'occurred_at' => now(),
            'metadata' => json_encode(['reference' => 'TXN-DEST', 'amount_minor' => 7000, 'recipient' => 'Juana  GCash 0917'])]);
        $this->runBackfill();
        $registry = app(FinancialReferenceRegistry::class);
        $refuse = function (string $ref, int $return, int $amount, ?string $destination) use ($registry): void {
            try {
                $registry->claim('refund', $ref, $this->source(ParcelReturn::class, $return), 'return:'.$return, $amount, $destination);
                $this->fail("Voided refund reference {$ref} was reused without evidence.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        };

        // The old void has no recorded destination: nothing proves it is the same transfer.
        $refuse('TXN-OLD', 4, 10000, 'Juana Cruz');
        $registry->claim('refund', 'TXN-OLD', $this->source(ParcelReturn::class, 4), 'return:4', 10000, 'Juana Cruz', 'refund_evidence:31');

        // Recorded destination: the same one re-records freely, another one needs evidence.
        $refuse('TXN-DEST', 6, 7000, 'Pedro BDO 1234');
        $registry->claim('refund', 'TXN-DEST', $this->source(ParcelReturn::class, 6), 'return:6', 7000, 'juana gcash 0917');
        $this->assertSame(['refund_evidence:31', null], FinancialReference::query()->whereNotNull('reuses_id')->orderBy('id')->pluck('evidence')->all());
    }

    public function test_partial_and_direct_remainder_payout_references_are_all_protected(): void
    {
        DB::statement('PRAGMA defer_foreign_keys = ON');
        // Settlement 1: ₱300 by request A, ₱200 by request B (closing it), so it carries B's reference.
        // Settlement 2: ₱100 by request C, the remaining ₱400 paid directly with its own reference.
        // Settlement 3: paid in full, later reopened for reconciliation after a return.
        DB::table('seller_payout_requests')->insert([
            ['id' => 1, 'seller_id' => 5, 'idempotency_key' => 'a', 'amount_minor' => 30000, 'status' => 'PAID', 'payout_reference' => 'PART-A', 'paid_at' => now()],
            ['id' => 2, 'seller_id' => 5, 'idempotency_key' => 'b', 'amount_minor' => 20000, 'status' => 'PAID', 'payout_reference' => 'PART-B', 'paid_at' => now()],
            ['id' => 3, 'seller_id' => 5, 'idempotency_key' => 'c', 'amount_minor' => 10000, 'status' => 'PAID', 'payout_reference' => 'PART-C', 'paid_at' => now()],
        ]);
        $settlement = fn (int $id): array => ['id' => $id, 'seller_order_id' => 10 + $id, 'cod_remittance_id' => $id, 'payment_id' => $id,
            'seller_id' => 5, 'collected_amount_minor' => 60000, 'shipping_allocation_minor' => 5000, 'paid_amount_minor' => 50000];
        DB::table('seller_settlements')->insert([
            ['status' => 'PAID', 'payout_reference' => 'PART-B'] + $settlement(1),
            ['status' => 'PAID', 'payout_reference' => 'DIRECT-REST'] + $settlement(2),
            ['status' => 'PENDING_PAYOUT', 'payout_reference' => 'REOPENED-1'] + $settlement(3),
        ]);
        DB::table('seller_payout_allocations')->insert([
            ['seller_payout_request_id' => 1, 'seller_settlement_id' => 1, 'amount_minor' => 30000],
            ['seller_payout_request_id' => 2, 'seller_settlement_id' => 1, 'amount_minor' => 20000],
            ['seller_payout_request_id' => 3, 'seller_settlement_id' => 2, 'amount_minor' => 10000],
        ]);

        $this->runBackfill();

        $this->assertEqualsCanonicalizing(['PART-A', 'PART-B', 'PART-C', 'DIRECT-REST', 'REOPENED-1'], FinancialReference::query()->pluck('normalized')->all());
        $this->assertSame(1, FinancialReference::query()->where('normalized', 'PART-B')->count(), 'A request and the settlement it closed are one transfer.');
        $direct = FinancialReference::query()->where('normalized', 'DIRECT-REST')->sole();
        $this->assertSame([(new SellerSettlement)->getMorphClass(), 2, 40000], [$direct->source_type, $direct->source_id, $direct->amount_minor]);

        foreach (['PART-A', 'DIRECT-REST', 'REOPENED-1'] as $reference) {
            try {
                app(FinancialReferenceRegistry::class)->claim('payout', $reference, $this->source(SellerSettlement::class, 99), 'seller:5', 40000);
                $this->fail("Historical payout reference {$reference} was reused.");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        // Running again adds nothing.
        $this->runBackfill();
        $this->assertSame(5, FinancialReference::query()->count());
    }

    public function test_the_backfill_skips_records_the_registry_already_holds(): void
    {
        $this->seedHistory();
        app(FinancialReferenceRegistry::class)->claim('payout', 'BDO 777', $this->source(SellerPayoutRequest::class, 1), 'seller:5', 58410);

        $this->runBackfill();

        $this->assertSame(1, FinancialReference::query()->where('normalized', 'BDO777')->count());
    }
}
