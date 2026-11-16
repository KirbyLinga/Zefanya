<?php

namespace Tests\Feature\Admin;

use App\Models\Logistics\LogisticsReturnCharge;
use App\Models\Logistics\ParcelLossCase;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\RiderPayout;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\FinancialReference;
use App\Services\Shared\FinancialReferenceRegistry;
use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A payment reference is single-use per scope: across every payout type, and
 * across refunds. A reversal or void releases it for the corrected payment.
 */
class FinancialReferenceRegistryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private FinancialReferenceRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(FinancialReferenceRegistry::class);
    }

    /** @param class-string<Model> $class */
    private function source(string $class, int $id): Model
    {
        $model = new $class;
        $model->forceFill([$model->getKeyName() => $id]);
        $model->exists = true;

        return $model;
    }

    public function test_a_payout_reference_cannot_be_reused_by_another_payout_type(): void
    {
        $this->registry->claim(FinancialReference::PAYOUT, 'GCASH-0001', $this->source(SellerPayoutRequest::class, 1));

        foreach ([SellerSettlement::class, RiderPayout::class, ParcelLossCase::class, LogisticsReturnCharge::class, SellerPayoutRequest::class] as $i => $class) {
            try {
                $this->registry->claim(FinancialReference::PAYOUT, 'GCASH-0001', $this->source($class, 100 + $i));
                $this->fail("{$class} reused a live payout reference.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('reference', $e->errors());
            }
        }

        $this->assertSame(1, FinancialReference::query()->count());
    }

    public function test_matching_ignores_case_and_spaces(): void
    {
        $this->registry->claim(FinancialReference::PAYOUT, 'bdo 123 456', $this->source(SellerSettlement::class, 1));

        $this->expectException(ValidationException::class);
        $this->registry->claim(FinancialReference::PAYOUT, ' BDO123456 ', $this->source(RiderPayout::class, 2));
    }

    public function test_the_same_record_can_repeat_its_claim(): void
    {
        $payout = $this->source(SellerPayoutRequest::class, 7);
        $this->registry->claim(FinancialReference::PAYOUT, 'REF-7', $payout);
        $this->registry->claim(FinancialReference::PAYOUT, 'REF-7', $payout);
        $this->registry->assertAvailable(FinancialReference::PAYOUT, 'REF-7', $payout);

        $this->assertSame(1, FinancialReference::query()->count());
    }

    public function test_refund_references_are_unique_among_refunds_only(): void
    {
        $this->registry->claim(FinancialReference::REFUND, 'TXN-1', $this->source(ParcelReturn::class, 1));
        // The scopes are separate ledgers: a payout may carry the same bank reference text.
        $this->registry->claim(FinancialReference::PAYOUT, 'TXN-1', $this->source(SellerSettlement::class, 1));

        try {
            $this->registry->claim(FinancialReference::REFUND, 'txn-1', $this->source(ParcelReturn::class, 2));
            $this->fail('A second refund reused a live refund reference.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('another refund', $e->errors()['reference'][0]);
        }

        $this->expectException(ValidationException::class);
        $this->registry->assertAvailable(FinancialReference::REFUND, 'TXN-1', $this->source(ParcelReturn::class, 2));
    }

    private function reversedRiderPayout(): void
    {
        $first = $this->source(RiderPayout::class, 1);
        $this->registry->claim(FinancialReference::PAYOUT, 'REV-1', $first, 'courier:7', 12000);
        $this->registry->release(FinancialReference::PAYOUT, $first);
    }

    public function test_a_reversed_reference_can_be_reused_only_by_its_verified_correction(): void
    {
        $this->reversedRiderPayout();

        $this->registry->claim(FinancialReference::PAYOUT, 'REV-1', $this->source(RiderPayout::class, 2), 'courier:7', 12000);

        $correction = FinancialReference::query()->where('source_id', 2)->sole();
        $original = FinancialReference::query()->where('source_id', 1)->sole();
        $this->assertSame($original->id, $correction->reuses_id);
        $this->assertSame('REV-1', $correction->active_key);
        $this->assertNull($original->active_key);
        $this->assertNotNull($original->released_at, 'The reversed record keeps its history.');
    }

    /** @return array<string, array{class-string<Model>, ?string, int}> */
    public static function unverifiedReuse(): array
    {
        return [
            'another recipient' => [RiderPayout::class, 'courier:8', 12000],
            'a different amount' => [RiderPayout::class, 'courier:7', 11999],
            'another payout type' => [SellerSettlement::class, 'courier:7', 12000],
            'no recipient evidence' => [RiderPayout::class, null, 12000],
        ];
    }

    /** @param class-string<Model> $class */
    #[DataProvider('unverifiedReuse')]
    public function test_a_reversed_reference_stays_protected_without_verified_evidence(string $class, ?string $recipient, int $amount): void
    {
        $this->reversedRiderPayout();

        try {
            $this->registry->claim(FinancialReference::PAYOUT, 'rev-1', $this->source($class, 2), $recipient, $amount);
            $this->fail('A reversed reference was reused without verified evidence.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('reversed payout', $e->errors()['reference'][0]);
        }

        $this->assertSame(1, FinancialReference::query()->count());
        $this->assertSame('REV-1', FinancialReference::query()->sole()->active_key);
    }

    public function test_a_reversed_payment_can_be_corrected_only_once(): void
    {
        $this->reversedRiderPayout();
        $second = $this->source(RiderPayout::class, 2);
        $this->registry->claim(FinancialReference::PAYOUT, 'REV-1', $second, 'courier:7', 12000);
        $this->registry->release(FinancialReference::PAYOUT, $second);

        // The correction itself was reversed; it may be corrected, the original may not again.
        $this->registry->claim(FinancialReference::PAYOUT, 'REV-1', $this->source(RiderPayout::class, 3), 'courier:7', 12000);
        $this->assertSame(
            [null, FinancialReference::query()->where('source_id', 1)->value('id'), FinancialReference::query()->where('source_id', 2)->value('id')],
            FinancialReference::query()->orderBy('id')->pluck('reuses_id')->all(),
        );

        $this->expectException(ValidationException::class);
        $this->registry->claim(FinancialReference::PAYOUT, 'REV-1', $this->source(RiderPayout::class, 4), 'courier:7', 12000);
    }

    public function test_a_live_reference_is_never_reusable_even_by_the_same_recipient_and_amount(): void
    {
        $this->registry->claim(FinancialReference::PAYOUT, 'LIVE-1', $this->source(RiderPayout::class, 1), 'courier:7', 12000);

        $this->expectException(ValidationException::class);
        $this->registry->claim(FinancialReference::PAYOUT, 'LIVE-1', $this->source(RiderPayout::class, 2), 'courier:7', 12000);
    }

    public function test_a_historical_duplicate_without_the_unique_key_still_blocks_reuse(): void
    {
        // Backfilled history may hold the same reference twice; only one row carries the key.
        foreach ([[1, 'OLD-9'], [2, null]] as [$id, $key]) {
            (new FinancialReference)->forceFill(['scope' => FinancialReference::PAYOUT, 'reference' => 'old-9', 'normalized' => 'OLD-9',
                'active_key' => $key, 'source_type' => (new RiderPayout)->getMorphClass(), 'source_id' => $id,
                'recipient' => 'courier:7', 'amount_minor' => 5000, 'released_at' => $id === 1 ? now() : null])->save();
        }

        $this->expectException(ValidationException::class);
        $this->registry->claim(FinancialReference::PAYOUT, 'OLD-9', $this->source(RiderPayout::class, 3), 'courier:7', 5000);
    }

    public function test_the_database_rejects_a_second_live_row_even_without_the_service_check(): void
    {
        $row = fn (int $id) => (new FinancialReference)->forceFill([
            'scope' => FinancialReference::PAYOUT, 'reference' => 'X', 'normalized' => 'X', 'active_key' => 'X',
            'source_type' => 'test', 'source_id' => $id,
        ])->save();
        $row(1);

        $this->expectException(UniqueConstraintViolationException::class);
        $row(2);
    }

    public function test_before_the_destination_migration_refunds_still_record_and_corrections_need_evidence(): void
    {
        Schema::table('financial_references', fn (Blueprint $table) => $table->dropColumn(['destination', 'evidence']));
        (new \ReflectionProperty(RuntimeSchema::class, 'columns'))->setValue(null, []);

        $return = $this->source(ParcelReturn::class, 1);
        $this->registry->claim(FinancialReference::REFUND, 'PRE-1', $return, 'return:1', 5000, 'Juana', 'refund_evidence:1');
        $this->registry->release(FinancialReference::REFUND, $return);

        try {
            // Even the same destination cannot be proven without the column: evidence is required.
            $this->registry->claim(FinancialReference::REFUND, 'PRE-1', $return, 'return:1', 5000, 'Juana');
            $this->fail('A voided refund reference was reused without evidence.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
        $this->registry->claim(FinancialReference::REFUND, 'PRE-1', $return, 'return:1', 5000, 'Juana', 'refund_evidence:2');
        $this->assertSame(1, FinancialReference::query()->whereNotNull('reuses_id')->count());

        (new \ReflectionProperty(RuntimeSchema::class, 'columns'))->setValue(null, []);
    }

    public function test_a_voided_refund_reference_needs_evidence_for_a_new_destination(): void
    {
        $return = $this->source(ParcelReturn::class, 1);
        $this->registry->claim(FinancialReference::REFUND, 'DEST-1', $return, 'return:1', 5000, 'Juana  GCash 0917');
        $this->registry->release(FinancialReference::REFUND, $return);

        try {
            $this->registry->claim(FinancialReference::REFUND, 'DEST-1', $return, 'return:1', 5000, 'Pedro BDO');
            $this->fail('A voided refund reference went to a new destination without evidence.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('different destination', $e->errors()['reference'][0]);
        }
        $this->registry->assertAvailable(FinancialReference::REFUND, 'DEST-1', $return, 'return:1', 5000, 'Pedro BDO', 'refund_evidence:submitted');

        // Same destination, spacing and case aside: the same transfer re-recorded.
        $this->registry->claim(FinancialReference::REFUND, 'DEST-1', $return, 'return:1', 5000, 'juana gcash 0917');
        $this->assertSame('juana gcash 0917', FinancialReference::query()->whereNotNull('reuses_id')->sole()->destination);
    }
}
