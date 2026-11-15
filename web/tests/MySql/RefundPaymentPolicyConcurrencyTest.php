<?php

namespace Tests\MySql;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Models\Admin\Admin;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsReturnCharge;
use App\Models\Logistics\ParcelReturn;
use App\Models\Logistics\PlatformRefundExpense;
use App\Models\Rider\Courier;
use App\Models\Seller\SellerDebt;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\ParcelReturnService;
use App\Support\RuntimeSchema;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RefundPaymentPolicyConcurrencyTest extends TestCase
{
    use UsesLogisticsDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Preflight::prepareSchema();
        Preflight::clean();
        if (! Schema::hasColumn('parcel_returns', 'refund_policy_version')) {
            (require database_path('migrations/pending/2026_11_14_120000_activate_refund_payment_policy.php'))->up();
        }
        $this->clearSchemaMemo();
    }

    protected function tearDown(): void
    {
        // Restore the isolated schema to the ordinary pre-activation regression layout.
        (require database_path('migrations/pending/2026_11_14_120000_activate_refund_payment_policy.php'))->down();
        $this->clearSchemaMemo();
        parent::tearDown();
    }

    private function clearSchemaMemo(): void
    {
        foreach (['columns', 'tables'] as $property) {
            (new \ReflectionProperty(RuntimeSchema::class, $property))->setValue(null, []);
        }
    }

    private function fixture(): array
    {
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $courier = Courier::factory()->create(['logistics_provider_id' => $provider->id]);
        $order = SellerOrder::factory()->status(OrderStatus::Delivered)->create(['subtotal_minor' => 45000]);
        $order->forceFill(['shipping_fee_minor' => 5000, 'commission_bps' => 500, 'commission_minor' => 2250,
            'seller_net_minor' => 42750, 'commission_finalized_at' => now()])->save();
        $payment = Payment::factory()->create(['seller_order_id' => $order->id, 'amount_minor' => 50000]);
        $payment->recordCodCollection(50000, 'rider', $courier->id);
        $remittance = (new CodRemittance)->forceFill(['seller_order_id' => $order->id, 'payment_id' => $payment->id,
            'courier_id' => $courier->id, 'logistics_provider_id' => $provider->id, 'amount_minor' => 50000,
            'status' => CodRemittance::PENDING_REMITTANCE]);
        $remittance->snapshotAllocation();
        $remittance->save();
        $return = (new ParcelReturn)->forceFill(['seller_order_id' => $order->id, 'status' => ParcelReturnStatus::Requested,
            'requested_at' => now(), 'reason' => 'Wrong item received', 'seller_response_due_at' => now()->addDays(3),
            'seller_decision' => ParcelReturn::APPROVED, 'fault_party' => 'seller', 'financial_review_required' => true, 'delivery_attempts' => 0]);
        $return->save();
        config(['filesystems.disks.local.root' => storage_path('framework/testing/disks/refund-policy-mysql-'.$return->id)]);
        Storage::forgetDisk('local');
        $proof = 'refund-proofs/'.$return->id.'/proof.pdf';
        Storage::disk('local')->put($proof, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");

        return ['return' => $return, 'order' => $order, 'remittance' => $remittance->fresh(), 'proof' => $proof,
            'admin' => Admin::factory()->create(['role' => 'admin']), 'other' => Admin::factory()->create(['role' => 'super_admin'])];
    }

    #[TestWith([50000])]
    #[TestWith([30000])]
    public function test_duplicate_confirmation_books_only_one_refund_and_expense(int $amount): void
    {
        $f = $this->fixture();
        $before = $f['remittance']->getAttributes();
        $financials = $f['order']->only(['commission_bps', 'commission_minor', 'seller_net_minor']);
        $service = app(ParcelReturnService::class);
        $service->authorizeRefund($f['return'], $f['admin'], $amount, 'platform', 'Finance approves the early platform-funded exception.', 'separate_approval', $f['proof']);
        $authorization = $f['return']->currentRefundAuthorization();
        $service->submitRefundEvidence($f['return'], $f['admin'], $authorization->id, 'MYSQL-POLICY-TRANSFER', 'Private buyer account', now('Asia/Manila')->toDateString(), $f['proof'], 'Final partial refund agreed with the buyer after review.', paidAmountMinor: $amount, paidBy: 'platform');
        $evidence = $f['return']->currentRefundSubmission();
        $task = ['action' => 'refund_policy_confirm', 'return' => $f['return']->id, 'payload' => [
            'evidence_id' => $evidence->id, 'verification_note' => 'Reviewed external provider confirmation, amount and recipient.']];
        $results = Race::run($task + ['admin' => $f['admin']->id, 'hold' => 'parcel'], $task + ['admin' => $f['other']->id, 'watch' => 'parcel']);
        $this->assertSame([302, 409], [$results['a']['status'], $results['b']['status']], json_encode($results));
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors']);
        }
        $this->assertSame(1, $f['return']->refundHistory()->count());
        $this->assertSame($amount, PlatformRefundExpense::sole()->amount_minor);
        $this->assertSame($amount, $f['return']->fresh()->refund_amount_minor);
        $this->assertSame(1, (int) $f['return']->fresh()->refund_policy_version);
        $this->assertSame(0, SellerDebt::count());
        $this->assertSame(0, LogisticsReturnCharge::count());
        $this->assertSame($before, $f['remittance']->fresh()->getAttributes());
        $this->assertSame($financials, $f['order']->fresh()->only(['commission_bps', 'commission_minor', 'seller_net_minor']));
    }

    public function test_activation_preserves_existing_historical_refund_and_cod_records(): void
    {
        $f = $this->fixture();
        $f['return']->forceFill(['refunded_at' => now(), 'refund_amount_minor' => 50000, 'refund_reference' => 'HISTORICAL-REFERENCE'])->save();
        $migration = require database_path('migrations/pending/2026_11_14_120000_activate_refund_payment_policy.php');
        $migration->down();
        $before = $f['return']->fresh()->getAttributes();
        $cod = $f['remittance']->fresh()->getAttributes();
        $migration->up();
        $this->clearSchemaMemo();
        $after = $f['return']->fresh();
        $this->assertNull($after->refund_policy_version);
        $attributes = $after->getAttributes();
        unset($attributes['refund_policy_version']);
        $this->assertSame($before, $attributes);
        $this->assertSame($cod, $f['remittance']->fresh()->getAttributes());
        $this->assertStringContainsString('Historical refund recorded', $after->refundPaymentLabel());
    }

    public function test_duplicate_authorization_cannot_replace_amount_or_payer(): void
    {
        $f = $this->fixture();
        // Normal receipt: no exception upload is needed by either independent HTTP worker.
        $f['return']->forceFill(['seller_received_at' => now(), 'received_by_seller_id' => $f['order']->seller_id])->save();
        foreach ([['collected_from_buyer', 'rider', now()->subHour()], ['station_received', 'logistics', now()->subMinutes(30)]] as [$key, $actor, $at]) {
            (new \App\Models\Logistics\ParcelReturnEvent)->forceFill(['parcel_return_id' => $f['return']->id, 'event_key' => $key, 'actor_type' => $actor,
                'actor_id' => 1, 'occurred_at' => $at, 'metadata' => ['station_hub_id' => 7, 'provider_id' => 3]])->save();
        }
        $task = ['action' => 'refund_policy_authorize', 'return' => $f['return']->id, 'payload' => [
            'amount' => '500.00', 'payer' => 'seller', 'authorization_note' => 'Finance approved the refund after verified Seller receipt.']];
        $results = Race::run($task + ['admin' => $f['admin']->id, 'hold' => 'parcel'], $task + ['admin' => $f['other']->id, 'watch' => 'parcel']);
        $this->assertSame([302, 409], [$results['a']['status'], $results['b']['status']], json_encode($results));
        foreach ($results as $result) {
            $this->assertSame([], $result['sql_errors']);
        }
        $this->assertSame(1, $f['return']->events()->where('event_key', 'like', 'refund_authorized%')->count());
        $this->assertNull($f['return']->fresh()->refunded_at);
        $this->assertSame(0, PlatformRefundExpense::count());
    }
}
