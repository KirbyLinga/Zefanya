<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerPayoutAllocation;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Admin\FinanceReportService;
use App\Services\Admin\PlatformFinanceService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Seller\SellerEarningsService;
use App\Services\Seller\SellerPayoutRequestService;
use App\Services\Seller\SellerSettlementService;
use App\Services\Seller\SellerTransactionService;
use App\Services\Shared\CommissionFinalizationService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SellerPayoutRequestTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function acceptedAmounts(): array
    {
        return ['minimum' => ['100.00', 10000, 48410], 'entire balance' => ['584.10', 58410, 0]];
    }

    #[DataProvider('acceptedAmounts')]
    public function test_sel_earn_005_accepts_the_minimum_and_maximum_without_changing_snapshots(string $amount, int $minor, int $available): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $before = $settlement->getRawOriginal();
        $orderBefore = $settlement->sellerOrder->getRawOriginal();

        $this->actingAs($seller, 'seller')->postJson(route('seller.payout-requests.store'), $this->submission($amount))
            ->assertCreated()->assertJsonPath('amount_minor', $minor)->assertJsonPath('status', SellerPayoutRequest::PENDING);

        $this->assertDatabaseCount('seller_payout_requests', 1);
        $this->assertSame($minor, (int) SellerPayoutAllocation::query()->sum('amount_minor'));
        $this->assertSame($available, app(SellerPayoutRequestService::class)->availableBalance($seller));
        $this->assertSame($before, $settlement->fresh()->getRawOriginal());
        $this->assertSame($orderBefore, $settlement->sellerOrder->fresh()->getRawOriginal());
    }

    public static function invalidAmounts(): array
    {
        return [
            'below minimum' => ['99.99'], 'above balance' => ['584.11'], 'zero' => ['0'],
            'negative' => ['-100'], 'nonnumeric' => ['abc'], 'extra decimals' => ['100.001'],
            'scientific notation' => ['1e2'], 'array' => [[100]], 'boolean' => [true],
            'missing' => [null], 'overflow' => ['9999999999999999999999999'],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function test_sel_earn_005_rejects_invalid_amounts_without_reserving_earnings(mixed $amount): void
    {
        $seller = Seller::factory()->create();
        $this->earnings($seller);

        $this->actingAs($seller, 'seller')->postJson(route('seller.payout-requests.store'), $this->submission($amount))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertDatabaseCount('seller_payout_requests', 0);
        $this->assertDatabaseCount('seller_payout_allocations', 0);
        $this->assertSame(58410, app(SellerPayoutRequestService::class)->availableBalance($seller));
    }

    public function test_sel_earn_006_blocks_a_second_pending_request_even_with_a_new_key(): void
    {
        $seller = Seller::factory()->create();
        $this->earnings($seller);
        app(SellerPayoutRequestService::class)->request($seller, '100', (string) Str::uuid());

        $this->actingAs($seller, 'seller')->postJson(route('seller.payout-requests.store'), $this->submission('100'))
            ->assertUnprocessable()->assertJsonValidationErrors('amount')
            ->assertJsonPath('errors.amount.0', 'A payout request is already pending.');

        $this->assertDatabaseCount('seller_payout_requests', 1);
        $this->assertSame(48410, app(SellerPayoutRequestService::class)->availableBalance($seller));
    }

    public function test_sel_earn_007_replays_a_duplicate_submission_without_reserving_twice(): void
    {
        $seller = Seller::factory()->create();
        $this->earnings($seller);
        $submission = $this->submission('100.00');
        $first = $this->actingAs($seller, 'seller')->postJson(route('seller.payout-requests.store'), $submission)->assertCreated();

        $this->postJson(route('seller.payout-requests.store'), $submission)
            ->assertOk()->assertJsonPath('id', $first->json('id'));
        $this->postJson(route('seller.payout-requests.store'), array_replace($submission, ['destination' => 'Different account']))
            ->assertOk()->assertJsonPath('payout_destination', $submission['destination']);

        $this->assertDatabaseCount('seller_payout_requests', 1);
        $this->assertDatabaseCount('seller_payout_allocations', 1);
        $this->assertSame(10000, (int) SellerPayoutAllocation::query()->sum('amount_minor'));
        $this->assertSame(48410, app(SellerPayoutRequestService::class)->availableBalance($seller));
    }

    public function test_a_reused_submission_key_cannot_change_the_amount(): void
    {
        $seller = Seller::factory()->create();
        $this->earnings($seller);
        $submission = $this->submission('100');
        app(SellerPayoutRequestService::class)->request($seller, $submission['amount'], $submission['idempotency_key']);

        $submission['amount'] = '200';
        $this->actingAs($seller, 'seller')->postJson(route('seller.payout-requests.store'), $submission)
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertSame(10000, SellerPayoutRequest::query()->sole()->amount_minor);
        $this->assertSame(48410, app(SellerPayoutRequestService::class)->availableBalance($seller));
    }

    public function test_uuid_case_does_not_create_another_submission(): void
    {
        $seller = Seller::factory()->create();
        $this->earnings($seller);
        $key = (string) Str::uuid();
        $service = app(SellerPayoutRequestService::class);

        $first = $service->request($seller, '100', strtoupper($key));
        $second = $service->request($seller, '100', $key);

        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('seller_payout_requests', 1);
        $this->assertSame(48410, $service->availableBalance($seller));
    }

    public function test_the_database_rejects_a_second_pending_reservation_for_the_same_seller(): void
    {
        $seller = Seller::factory()->create();
        $this->earnings($seller);
        $service = app(SellerPayoutRequestService::class);
        $service->request($seller, '100', (string) Str::uuid());

        try {
            DB::transaction(function () use ($seller): void {
                (new SellerPayoutRequest)->forceFill([
                    'seller_id' => $seller->id, 'pending_seller_id' => $seller->id,
                    'amount_minor' => 10000, 'idempotency_key' => (string) Str::uuid(),
                    'status' => SellerPayoutRequest::PENDING,
                ])->save();
            });
            $this->fail('A second pending reservation must be rejected by the database.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('pending_seller_id', $exception->getMessage());
        }

        $this->assertDatabaseCount('seller_payout_requests', 1);
        $this->assertDatabaseCount('seller_payout_allocations', 1);
        $this->assertSame(48410, $service->availableBalance($seller));
    }

    public static function invalidKeys(): array
    {
        return [[null], ['not-a-uuid'], [['invalid']]];
    }

    #[DataProvider('invalidKeys')]
    public function test_a_submission_requires_a_valid_idempotency_key(mixed $key): void
    {
        $seller = Seller::factory()->create();

        $this->actingAs($seller, 'seller')->postJson(route('seller.payout-requests.store'), ['amount' => '100', 'idempotency_key' => $key, 'destination' => 'GCash 09171234567'])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');

        $this->assertDatabaseCount('seller_payout_requests', 0);
    }

    public function test_cancellation_releases_funds_once_and_retains_request_history(): void
    {
        $this->freezeTime();
        $seller = Seller::factory()->create();
        $this->earnings($seller);
        $submission = $this->submission('100');
        $service = app(SellerPayoutRequestService::class);
        $payout = $service->request($seller, $submission['amount'], $submission['idempotency_key']);

        $this->actingAs($seller, 'seller')->deleteJson(route('seller.payout-requests.destroy', $payout))
            ->assertOk()->assertJsonPath('status', SellerPayoutRequest::CANCELLED);
        $this->deleteJson(route('seller.payout-requests.destroy', $payout))->assertOk();

        $this->assertSame(58410, $service->availableBalance($seller));
        $this->assertNull($payout->fresh()->pending_seller_id);
        $this->assertNotNull($payout->fresh()->cancelled_at);
        $this->assertDatabaseCount('seller_payout_allocations', 1);
        // A late retry of the cancelled submission must not create a new reservation.
        $this->postJson(route('seller.payout-requests.store'), $submission)
            ->assertOk()->assertJsonPath('status', SellerPayoutRequest::CANCELLED);
        $this->assertSame(58410, $service->availableBalance($seller));
        $this->postJson(route('seller.payout-requests.store'), $this->submission('584.10'))->assertCreated();
        $this->assertSame(0, $service->availableBalance($seller));
        $this->assertDatabaseCount('seller_payout_requests', 2);
    }

    public function test_reservations_can_span_multiple_settlements(): void
    {
        $seller = Seller::factory()->create();
        $first = $this->earnings($seller);
        $second = $this->earnings($seller);

        $this->actingAs($seller, 'seller')->postJson(route('seller.payout-requests.store'), $this->submission('700'))->assertCreated();

        $this->assertDatabaseHas('seller_payout_allocations', ['seller_settlement_id' => $first->id, 'amount_minor' => 58410]);
        $this->assertDatabaseHas('seller_payout_allocations', ['seller_settlement_id' => $second->id, 'amount_minor' => 11590]);
        $this->assertSame(46820, app(SellerPayoutRequestService::class)->availableBalance($seller));
    }

    public function test_historical_finalized_commissions_are_used_without_recalculation(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        // Historical 5% snapshot: gross 69900, seller discount 5000, commission 3245, net 61655.
        $order = $settlement->sellerOrder;
        $order->forceFill(['commission_bps' => 500, 'commission_minor' => 3245, 'seller_net_minor' => 61655])->save();
        $settlement->forceFill(['commission_bps' => 500, 'commission_minor' => 3245, 'seller_payout_minor' => 61655])->save();
        $before = $order->getRawOriginal();

        $this->actingAs($seller, 'seller')->postJson(route('seller.payout-requests.store'), $this->submission('616.55'))->assertCreated();

        $this->assertSame(0, app(SellerPayoutRequestService::class)->availableBalance($seller));
        $this->assertSame($before, $order->fresh()->getRawOriginal());
    }

    public function test_a_seller_cannot_withdraw_another_sellers_earnings_or_cancel_their_request(): void
    {
        $owner = Seller::factory()->create();
        $other = Seller::factory()->create();
        $this->earnings($owner);
        $submission = $this->submission('100');
        $payout = app(SellerPayoutRequestService::class)->request($owner, '100', $submission['idempotency_key']);

        $this->actingAs($other, 'seller')->get(route('seller.payout-requests.index'))
            ->assertOk()->assertViewHas('availableMinor', 0)->assertViewHas('payouts', fn ($rows) => $rows->isEmpty());
        $this->postJson(route('seller.payout-requests.store'), $submission + ['seller_id' => $owner->id])
            ->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->deleteJson(route('seller.payout-requests.destroy', $payout))->assertNotFound();

        $this->assertSame(SellerPayoutRequest::PENDING, $payout->fresh()->status);
        $this->assertSame(48410, app(SellerPayoutRequestService::class)->availableBalance($owner));
    }

    public function test_submission_keys_are_scoped_to_each_seller(): void
    {
        $first = Seller::factory()->create();
        $second = Seller::factory()->create();
        $this->earnings($first);
        $this->earnings($second);
        $key = (string) Str::uuid();

        $service = app(SellerPayoutRequestService::class);
        $one = $service->request($first, '100', $key);
        $two = $service->request($second, '100', $key);

        $this->assertNotSame($one->id, $two->id);
        $this->assertSame(48410, $service->availableBalance($first));
        $this->assertSame(48410, $service->availableBalance($second));
    }

    public static function excludedEarnings(): array
    {
        return array_map(fn ($case) => [$case], [
            'not_completed', 'not_finalized', 'unknown_net', 'pending_reconciliation', 'unconfirmed_remittance', 'not_received_by_zefanya',
            'wrong_collection', 'wrong_settlement_net', 'wrong_shipping', 'wrong_owner', 'unknown_funding', 'already_paid',
        ]);
    }

    #[DataProvider('excludedEarnings')]
    public function test_only_completed_finalized_and_reconciled_unpaid_earnings_are_available(string $case): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $order = $settlement->sellerOrder;
        match ($case) {
            'not_completed' => $order->forceFill(['status' => OrderStatus::Delivered])->save(),
            'not_finalized' => $order->forceFill(['commission_finalized_at' => null])->save(),
            'unknown_net' => $order->forceFill(['seller_net_minor' => null])->save(),
            'pending_reconciliation' => $settlement->forceFill(['pending_reason' => SellerSettlement::FINANCIAL_RECONCILIATION_REQUIRED])->save(),
            'unconfirmed_remittance' => $settlement->codRemittance->forceFill(['confirmed_at' => null])->save(),
            'not_received_by_zefanya' => $settlement->codRemittance->forceFill(['zefanya_received_at' => null])->save(),
            'wrong_collection' => $settlement->payment->forceFill(['collected_amount_minor' => 1])->save(),
            'wrong_settlement_net' => $settlement->forceFill(['seller_payout_minor' => 99999])->save(),
            'wrong_shipping' => $settlement->forceFill(['shipping_allocation_minor' => 1])->save(),
            'wrong_owner' => $order->forceFill(['seller_id' => Seller::factory()->create()->id])->save(),
            'unknown_funding' => $order->forceFill(['voucher_funding_source' => null])->save(),
            'already_paid' => $settlement->forceFill(['status' => SellerSettlement::PAID, 'paid_amount_minor' => 58410, 'paid_at' => now()])->save(),
        };

        $this->actingAs($seller, 'seller')->postJson(route('seller.payout-requests.store'), $this->submission('100'))
            ->assertUnprocessable()->assertJsonValidationErrors('amount');

        $this->assertSame(0, app(SellerPayoutRequestService::class)->availableBalance($seller));
        $this->assertDatabaseCount('seller_payout_requests', 0);
        $this->assertDatabaseCount('seller_payout_allocations', 0);
    }

    public function test_a_direct_admin_payout_cannot_spend_reserved_earnings_but_can_pay_after_cancellation(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $payout = app(SellerPayoutRequestService::class)->request($seller, '100', (string) Str::uuid());
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'admin');
        $input = ['amount_minor' => 58410, 'reference' => 'RULE09-DIRECT', 'reason' => 'Seller asked for a direct transfer'];

        $this->postJson(route('admin.settlements.confirm', $settlement), $input)
            ->assertUnprocessable()->assertJsonValidationErrors('settlement');
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        app(SellerPayoutRequestService::class)->cancel($seller, $payout->id);
        $this->postJson(route('admin.settlements.confirm', $settlement), $input)
            ->assertOk()->assertJsonPath('status', SellerSettlement::PAID);

        $this->assertSame(0, app(SellerPayoutRequestService::class)->availableBalance($seller));
    }

    public function test_a_payout_request_needs_somewhere_to_send_the_money(): void
    {
        $seller = Seller::factory()->create();
        $this->earnings($seller);

        $this->actingAs($seller, 'seller')
            ->postJson(route('seller.payout-requests.store'), ['amount' => '100', 'idempotency_key' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonValidationErrors('destination');

        $this->postJson(route('seller.payout-requests.store'), $this->submission('100'))->assertCreated();
        $this->assertSame('GCash 09171234567', SellerPayoutRequest::query()->sole()->payout_destination);
    }

    public function test_invalid_payout_preserves_only_the_submitted_form_context(): void
    {
        $seller = Seller::factory()->create();
        $this->earnings($seller);
        $payout = app(SellerPayoutRequestService::class)->request($seller, '584.10', (string) Str::uuid(), 'GCash 09171234567');
        $admin = Admin::factory()->create(['role' => 'admin']);
        $action = route('admin.seller-payouts.pay', $payout);
        $reference = str_repeat('R', 256);

        $this->actingAs($admin, 'admin')->from(route('admin.seller-payouts.index'))->followingRedirects()
            ->post($action, ['_ui_form' => $action, 'reference' => $reference])
            ->assertOk()->assertSee('name="_ui_form"', false)->assertSee($reference)->assertSee('Please correct the following:');
        $this->assertSame(SellerPayoutRequest::PENDING, $payout->fresh()->status);
    }

    public function test_admin_pays_a_full_request_and_the_seller_is_told(): void
    {
        $seller = Seller::factory()->create(['business_name' => 'EdGaming']);
        $settlement = $this->earnings($seller);
        $service = app(SellerPayoutRequestService::class);
        $payout = $service->request($seller, '584.10', (string) Str::uuid(), 'GCash 09171234567');
        $admin = Admin::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'admin')->get(route('admin.seller-payouts.index'))
            ->assertOk()->assertSee('EdGaming')->assertSee('₱584.10')->assertSee('GCash 09171234567');

        $this->post(route('admin.seller-payouts.pay', $payout), [])->assertSessionHasErrors('reference');
        $this->post(route('admin.seller-payouts.pay', $payout), [
            'reference' => 'BDO-777',
            'amount_minor' => 1,
            'payout_destination' => 'Different account',
            'status' => SellerPayoutRequest::CANCELLED,
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $payout->refresh();
        $this->assertSame(SellerPayoutRequest::PAID, $payout->status);
        $this->assertNull($payout->pending_seller_id);
        $this->assertSame('BDO-777', $payout->payout_reference);
        $this->assertSame(58410, $payout->amount_minor);
        $this->assertSame('GCash 09171234567', $payout->payout_destination);
        $this->assertSame($admin->id, (int) $payout->decided_by_admin_id);
        $this->assertSame(SellerSettlement::PAID, $settlement->fresh()->status);
        $this->assertSame(58410, $settlement->fresh()->paid_amount_minor);
        $this->assertSame(0, $service->availableBalance($seller));
        $this->assertSame('seller_payout_paid', $seller->notifications()->sole()->data['type']);
        $this->assertSame('₱584.10', OperationsAuditLog::query()->where('action', 'seller_payout_paid')->sole()->new_values['amount']);

        $this->post(route('admin.seller-payouts.pay', $payout), ['reference' => 'RETRY'])
            ->assertSessionHas('success', 'This payout was already recorded.');
        $this->assertSame('BDO-777', $payout->fresh()->payout_reference);
        $this->assertSame(58410, $settlement->fresh()->paid_amount_minor);
        $this->assertDatabaseCount('seller_payout_allocations', 1);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'seller_payout_paid')->count());
        $this->assertSame(1, $seller->notifications()->count());
        $this->actingAs($seller, 'seller')->get(route('seller.payout-requests.index'))->assertOk()->assertSee('Reference: BDO-777');
    }

    public function test_a_part_payment_leaves_the_rest_available_and_is_not_paid_twice(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $service = app(SellerPayoutRequestService::class);
        $admin = Admin::factory()->create(['role' => 'admin']);

        $service->pay($service->request($seller, '100', (string) Str::uuid()), $admin, 'PART-1');

        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertSame(10000, $settlement->fresh()->paid_amount_minor);
        $this->assertSame(48410, $service->availableBalance($seller));

        try {
            $service->request($seller, '484.11', (string) Str::uuid());
            $this->fail('A request above what is left must be refused.');
        } catch (ValidationException) {
            $this->assertSame(48410, $service->availableBalance($seller));
        }

        $service->pay($service->request($seller, '484.10', (string) Str::uuid()), $admin, 'PART-2');

        $this->assertSame(SellerSettlement::PAID, $settlement->fresh()->status);
        $this->assertSame(58410, $settlement->fresh()->paid_amount_minor);
        $this->assertSame('PART-2', $settlement->fresh()->payout_reference);
        $this->assertSame(0, $service->availableBalance($seller));
    }

    public function test_a_direct_payout_after_a_part_payment_only_covers_what_is_left(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $service = app(SellerPayoutRequestService::class);
        $admin = Admin::factory()->create(['role' => 'admin']);
        $service->pay($service->request($seller, '100', (string) Str::uuid()), $admin, 'PART-1');

        $direct = app(SellerSettlementService::class);
        try {
            $direct->confirmPayout($settlement->fresh(), $admin, 58410, 'TOO-MUCH');
            $this->fail('Paying the full amount again must be refused.');
        } catch (ValidationException) {
            $this->assertSame(10000, $settlement->fresh()->paid_amount_minor);
        }

        $direct->confirmPayout($settlement->fresh(), $admin, 48410, 'THE-REST');
        $this->assertSame(SellerSettlement::PAID, $settlement->fresh()->status);
        $this->assertSame(58410, $settlement->fresh()->paid_amount_minor);
    }

    public function test_admin_rejects_a_request_and_the_earnings_come_back(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $service = app(SellerPayoutRequestService::class);
        $payout = $service->request($seller, '584.10', (string) Str::uuid());
        $this->assertSame(0, $service->availableBalance($seller));

        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin');
        $this->post(route('admin.seller-payouts.reject', $payout), [])->assertSessionHasErrors('reason');
        $this->post(route('admin.seller-payouts.reject', $payout), ['reason' => 'GCash number not registered'])->assertSessionHas('success');

        $this->assertSame(SellerPayoutRequest::REJECTED, $payout->fresh()->status);
        $this->assertSame(58410, $service->availableBalance($seller));
        $this->assertNull($settlement->fresh()->paid_amount_minor);
        $this->assertSame('seller_payout_rejected', $seller->notifications()->sole()->data['type']);
        $this->post(route('admin.seller-payouts.reject', $payout), ['reason' => 'Retry'])->assertSessionHasErrors('payout');
        $this->assertSame(58410, $service->availableBalance($seller));
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'seller_payout_rejected')->count());

        $this->post(route('admin.seller-payouts.pay', $payout), ['reference' => 'LATE'])->assertSessionHasErrors('payout');
        $this->assertSame(SellerPayoutRequest::REJECTED, $payout->fresh()->status);

        $this->actingAs($seller, 'seller')->get(route('seller.payout-requests.index'))->assertOk()->assertSee('GCash number not registered');
    }

    public function test_a_request_whose_earnings_stopped_reconciling_cannot_be_paid(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $payout = app(SellerPayoutRequestService::class)->request($seller, '100', (string) Str::uuid());
        $settlement->codRemittance->forceFill(['zefanya_received_at' => null])->save();

        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')
            ->post(route('admin.seller-payouts.pay', $payout), ['reference' => 'TOO-EARLY'])
            ->assertSessionHasErrors('payout');

        $this->assertSame(SellerPayoutRequest::PENDING, $payout->fresh()->status);
        $this->assertNull($settlement->fresh()->paid_amount_minor);
    }

    public function test_moderators_cannot_open_or_pay_seller_payouts(): void
    {
        $seller = Seller::factory()->create();
        $this->earnings($seller);
        $payout = app(SellerPayoutRequestService::class)->request($seller, '100', (string) Str::uuid());

        $this->actingAs(Admin::factory()->create(['role' => 'moderator']), 'admin');
        $this->get(route('admin.seller-payouts.index'))->assertForbidden();
        $this->post(route('admin.seller-payouts.pay', $payout), ['reference' => 'NOPE'])->assertForbidden();

        $this->assertSame(SellerPayoutRequest::PENDING, $payout->fresh()->status);
    }

    public function test_a_seller_cannot_be_paid_until_zefanya_confirms_receiving_the_cash(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $settlement->codRemittance->forceFill(['zefanya_received_at' => null])->save();
        $service = app(SellerPayoutRequestService::class);
        $this->assertSame(0, $service->availableBalance($seller));

        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin');
        $input = ['amount_minor' => 58410, 'reference' => 'TOO-EARLY', 'reason' => 'Seller asked for a direct transfer'];
        $this->postJson(route('admin.settlements.confirm', $settlement), $input)
            ->assertUnprocessable()->assertJsonValidationErrors('settlement');
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);

        $settlement->codRemittance->forceFill(['zefanya_received_at' => now()])->save();
        $this->assertSame(58410, $service->availableBalance($seller));
        $this->postJson(route('admin.settlements.confirm', $settlement), $input)
            ->assertOk()->assertJsonPath('status', SellerSettlement::PAID);
    }

    public function test_paying_an_unreserved_settlement_does_not_spend_a_pending_reservation(): void
    {
        $seller = Seller::factory()->create();
        $this->earnings($seller);
        $unreserved = $this->earnings($seller);
        app(SellerPayoutRequestService::class)->request($seller, '100', (string) Str::uuid());

        app(SellerSettlementService::class)->confirmPayout($unreserved, Admin::factory()->create(), 58410, 'UNRESERVED');

        $this->assertSame(48410, app(SellerPayoutRequestService::class)->availableBalance($seller));
    }

    public function test_a_failed_allocation_rolls_back_the_request_and_reservation(): void
    {
        $seller = Seller::factory()->create();
        $this->earnings($seller);
        SellerPayoutAllocation::creating(fn () => throw new \RuntimeException('Allocation failed'));
        try {
            app(SellerPayoutRequestService::class)->request($seller, '100', (string) Str::uuid());
            $this->fail('The allocation failure must abort the request.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Allocation failed', $exception->getMessage());
        } finally {
            SellerPayoutAllocation::flushEventListeners();
        }

        $this->assertDatabaseCount('seller_payout_requests', 0);
        $this->assertDatabaseCount('seller_payout_allocations', 0);
        $this->assertSame(58410, app(SellerPayoutRequestService::class)->availableBalance($seller));
    }

    public static function nonSellerRoles(): array
    {
        return [['buyer', Buyer::class], ['admin', Admin::class], ['logistics', LogisticsProvider::class], ['rider', Rider::class]];
    }

    #[DataProvider('nonSellerRoles')]
    public function test_other_roles_cannot_use_seller_payout_routes(string $guard, string $model): void
    {
        $this->actingAs($model::factory()->create(), $guard);

        $this->getJson(route('seller.payout-requests.index'))->assertUnauthorized();
        $this->postJson(route('seller.payout-requests.store'), $this->submission('100'))->assertUnauthorized();
        $this->deleteJson(route('seller.payout-requests.destroy', 1))->assertUnauthorized();

        $this->assertDatabaseCount('seller_payout_requests', 0);
    }

    public function test_guests_and_unapproved_sellers_cannot_reserve_earnings(): void
    {
        $this->postJson(route('seller.payout-requests.store'), $this->submission('100'))->assertUnauthorized();
        $seller = Seller::factory()->pending()->create();
        $this->actingAs($seller, 'seller')->post(route('seller.payout-requests.store'), $this->submission('100'))
            ->assertForbidden()->assertSee('under review');
        $this->assertAuthenticatedAs($seller, 'seller');

        $this->assertDatabaseCount('seller_payout_requests', 0);
    }

    public function test_browser_form_shows_available_pending_and_cancelled_history(): void
    {
        $seller = Seller::factory()->create();
        $this->earnings($seller);
        $response = $this->actingAs($seller, 'seller')->get(route('seller.payout-requests.index'))
            ->assertOk()->assertSee('₱584.10')->assertSee('name="idempotency_key"', false);

        $this->post(route('seller.payout-requests.store'), ['amount' => '100', 'idempotency_key' => $response->viewData('idempotencyKey'), 'destination' => 'GCash 09171234567'])
            ->assertRedirect(route('seller.payout-requests.index'));
        $this->get(route('seller.payout-requests.index'))->assertSee('₱484.10')->assertSee('A payout request is already pending');
        $payout = SellerPayoutRequest::query()->sole();
        $this->delete(route('seller.payout-requests.destroy', $payout))->assertRedirect(route('seller.payout-requests.index'));
        $this->get(route('seller.payout-requests.index'))->assertSee('₱584.10')->assertSee('Cancelled');
    }

    public function test_admin_sees_what_platform_funded_vouchers_cost_against_commission(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $settlement->forceFill(['marketplace_funded_discount_minor' => 15000])->save();
        $this->earnings($seller)->forceFill(['marketplace_funded_discount_minor' => 0])->save();

        $summary = app(SellerSettlementService::class)->subsidySummary();

        $this->assertSame(15000, $summary['all']['subsidyMinor']);
        $this->assertSame(15000, $summary['all']['pendingSubsidyMinor']);
        $this->assertSame(0, $summary['all']['paidSubsidyMinor']);
        $this->assertSame(1, $summary['all']['orders']);
        $this->assertSame(12980, $summary['all']['commissionMinor']);
        $this->assertSame(-2020, $summary['all']['netMinor']);
        $this->assertSame($summary['all'], $summary['month']);

        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')
            ->get(route('admin.logistics-remittances.index'))
            ->assertOk()->assertSee('Platform voucher cost')->assertSee('₱150.00')->assertSee('₱20.20 short');
    }

    private function paidThenReturned(Seller $seller): SellerSettlement
    {
        $settlement = $this->earnings($seller);
        $service = app(SellerPayoutRequestService::class);
        $payout = $service->request($seller, '584.10', (string) Str::uuid(), 'GCash 09171234567');
        $service->pay($payout, Admin::factory()->create(['role' => 'admin']), 'FIRST-PAYOUT');
        $this->assertSame(SellerSettlement::PAID, $settlement->fresh()->status);

        ParcelReturn::query()->forceCreate([
            'seller_order_id' => $settlement->seller_order_id,
            'status' => ParcelReturnStatus::Requested->value,
            'reason' => 'Item arrived damaged',
            'requested_at' => now(),
            'seller_decision' => ParcelReturn::APPROVED,
        ]);
        app(SellerSettlementService::class)->releasePendingForReturn($settlement->sellerOrder);

        return $settlement->fresh();
    }

    public function test_a_return_after_the_seller_was_paid_becomes_a_debt_taken_from_later_payouts_once(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->paidThenReturned($seller);
        $service = app(SellerPayoutRequestService::class);

        $debt = SellerDebt::sole();
        $this->assertSame(58410, $debt->amount_minor);
        $this->assertSame($settlement->seller_order_id, $debt->seller_order_id);
        $this->assertSame(SellerSettlement::PAID, $settlement->status, 'the paid settlement stays as the record');

        app(SellerSettlementService::class)->releasePendingForReturn($settlement->sellerOrder);
        $this->assertSame(1, SellerDebt::query()->count(), 'a repeat call does not add a second debt');
        $this->assertSame(58410, $service->outstandingDebtMinor($seller));

        $this->earnings($seller);
        $request = $service->request($seller, '584.10', (string) Str::uuid(), 'GCash 09171234567');
        $this->assertSame(58410, $request->debt_deducted_minor);
        $this->assertSame(58410, $service->outstandingDebtMinor($seller), 'nothing is repaid until Admin records the payout');

        $service->cancel($seller, $request->id);
        $this->assertSame(58410, $service->outstandingDebtMinor($seller));

        $request = $service->request($seller, '584.10', (string) Str::uuid(), 'GCash 09171234567');
        $service->pay($request, Admin::factory()->create(['role' => 'admin']), 'REPAY-1');

        $this->assertSame(0, $service->outstandingDebtMinor($seller));
        $this->assertSame(58410, $debt->fresh()->settled_minor);
        $this->assertSame(0, $request->fresh()->amount_minor - $request->fresh()->debt_deducted_minor);
    }

    public function test_only_the_part_of_a_payout_above_the_debt_is_sent_to_the_seller(): void
    {
        $seller = Seller::factory()->create();
        $this->paidThenReturned($seller);
        $this->earnings($seller);
        $this->earnings($seller);
        $service = app(SellerPayoutRequestService::class);

        $request = $service->request($seller, '1000', (string) Str::uuid(), 'GCash 09171234567');

        $this->assertSame(100000, $request->amount_minor);
        $this->assertSame(58410, $request->debt_deducted_minor);
        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')
            ->get(route('admin.seller-payouts.index'))
            ->assertOk()->assertSee('Send only the amount above')->assertSee('415.90');
    }

    public function test_future_payout_recovers_two_order_debts_once_oldest_first(): void
    {
        $seller = Seller::factory()->create();
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $settlements = app(SellerSettlementService::class);
        $older = $settlements->confirmPayout($this->earnings($seller), $admin, 58410, 'OLDER');
        $newer = $settlements->confirmPayout($this->earnings($seller), $admin, 58410, 'NEWER');
        $debts = collect([[$older, 50000], [$newer, 30000]])->map(fn (array $entry) => SellerDebt::query()->forceCreate([
            'seller_id' => $seller->id, 'seller_order_id' => $entry[0]->seller_order_id,
            'seller_settlement_id' => $entry[0]->id, 'amount_minor' => $entry[1], 'reason' => SellerDebt::AFTER_PAYOUT_RETURN,
        ]));
        $this->earnings($seller);
        $this->earnings($seller);
        $service = app(SellerPayoutRequestService::class);

        $first = $service->request($seller, '600', (string) Str::uuid());
        $this->assertTrue($service->pay($first, $admin, 'RECOVERY-1'));
        $this->assertFalse($service->pay($first, $admin, 'RETRY'));
        $this->assertSame(50000, $debts[0]->fresh()->settled_minor);
        $this->assertSame(10000, $debts[1]->fresh()->settled_minor);
        $this->assertSame(20000, $service->outstandingDebtMinor($seller));

        $second = $service->request($seller, '400', (string) Str::uuid());
        $this->assertSame(20000, $second->debt_deducted_minor);
        $this->assertTrue($service->pay($second, $admin, 'RECOVERY-2'));
        $this->assertSame(30000, $debts[1]->fresh()->settled_minor);
        $this->assertSame(0, $service->outstandingDebtMinor($seller));
        $this->assertSame(16820, $service->availableBalance($seller));
    }

    public function test_suspending_a_seller_with_no_new_earnings_keeps_debt_and_payout_history(): void
    {
        $seller = Seller::factory()->create();
        $paid = $this->paidThenReturned($seller);
        $before = SellerDebt::query()->sole()->getAttributes();
        $service = app(SellerPayoutRequestService::class);
        $this->assertSame(0, $service->availableBalance($seller));
        $admin = Admin::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin, 'admin')->post(route('admin.users.seller.suspend', $seller), ['reason' => 'Reviewed by support.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('suspended', $seller->fresh()->status);
        $this->assertSame($before, SellerDebt::query()->sole()->getAttributes());
        $this->assertSame(58410, app(PlatformFinanceService::class)->position()['sellerDebtMinor']);
        $this->assertSame(58410, $paid->fresh()->paid_amount_minor);
        $this->get(route('admin.seller-payouts.index'))->assertOk()->assertSee('FIRST-PAYOUT');
    }

    public function test_new_refund_debt_invalidates_a_pending_payout_without_a_deduction(): void
    {
        $seller = Seller::factory()->create();
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $paid = app(SellerSettlementService::class)->confirmPayout($this->earnings($seller), $admin, 58410, 'EARLIER');
        $earnings = $this->earnings($seller);
        $service = app(SellerPayoutRequestService::class);
        $payout = $service->request($seller, '584.10', (string) Str::uuid());
        $this->assertSame(0, $payout->debt_deducted_minor);

        $return = ParcelReturn::query()->forceCreate([
            'seller_order_id' => $paid->seller_order_id,
            'status' => ParcelReturnStatus::Requested->value,
            'reason' => 'Item arrived damaged', 'requested_at' => now(),
            'seller_decision' => ParcelReturn::APPROVED, 'financial_review_required' => true,
        ]);
        app(ParcelReturnService::class)->recordRefund($return, $admin, 'LATER-REFUND', 10000,
            'Buyer', today()->toDateString(), reason: 'Buyer kept the remaining items.');
        $this->assertSame(9000, $service->outstandingDebtMinor($seller));

        $this->actingAs($admin, 'admin')->postJson(route('admin.seller-payouts.pay', $payout), ['reference' => 'STALE'])
            ->assertUnprocessable()->assertJsonValidationErrors('payout');
        $this->assertSame(SellerPayoutRequest::PENDING, $payout->fresh()->status);
        $this->assertNull($earnings->fresh()->paid_amount_minor);
        $this->assertSame(0, SellerDebt::sole()->settled_minor);
        $this->assertDatabaseMissing('operations_audit_logs', ['action' => 'seller_payout_paid']);

        $service->reject($payout, $admin, 'Refund changed the deduction.');
        $replacement = $service->request($seller, '584.10', (string) Str::uuid());
        $this->assertSame(9000, $replacement->debt_deducted_minor);
        $this->assertTrue($service->pay($replacement, $admin, 'CURRENT'));
        $this->assertSame(0, $service->outstandingDebtMinor($seller));
    }

    public function test_partial_debt_recovery_keeps_the_original_amount_and_never_sends_a_negative_payout(): void
    {
        $seller = Seller::factory()->create();
        $order = SellerOrder::factory()->create(['seller_id' => $seller->id]);
        $debt = SellerDebt::query()->forceCreate([
            'seller_id' => $seller->id, 'seller_order_id' => $order->id,
            'seller_settlement_id' => null, 'amount_minor' => 200000, 'reason' => SellerDebt::FAILED_DELIVERY,
        ]);
        $this->earnings($seller);
        $this->earnings($seller);
        $service = app(SellerPayoutRequestService::class);
        $payout = $service->request($seller, '700.00', (string) Str::uuid());
        $this->assertSame(70000, $payout->debt_deducted_minor);
        $this->assertTrue($service->pay($payout, Admin::factory()->create(['role' => 'admin']), 'PARTIAL-DEBT'));
        $this->assertSame(200000, $debt->fresh()->amount_minor);
        $this->assertSame(70000, $debt->fresh()->settled_minor);
        $this->assertSame(130000, $debt->fresh()->outstandingMinor());
        $this->assertSame(130000, app(PlatformFinanceService::class)->position()['sellerDebtMinor']);
        $this->assertSame(0, $payout->amount_minor - $payout->debt_deducted_minor);
        $this->assertFalse($service->pay($payout, Admin::factory()->create(['role' => 'admin']), 'RETRY'));
        $this->assertSame(70000, $debt->fresh()->settled_minor);
    }

    public function test_forged_debt_fields_on_a_seller_payout_cannot_change_the_balance_or_deduction(): void
    {
        $seller = Seller::factory()->create();
        $this->paidThenReturned($seller);
        $this->earnings($seller);
        $before = SellerDebt::sole()->getAttributes();
        $this->actingAs($seller, 'seller')->postJson(route('seller.payout-requests.store'), $this->submission('200.00') + [
            'debt_deducted_minor' => 0, 'settled_minor' => 58410, 'amount_minor' => 0, 'status' => 'PAID',
        ])->assertCreated()->assertJsonPath('debt_deducted_minor', 20000)->assertJsonPath('status', SellerPayoutRequest::PENDING);
        $this->assertSame($before, SellerDebt::sole()->getAttributes());
    }

    public function test_old_debt_without_earnings_remains_outstanding_and_blocks_parent_deletion(): void
    {
        $seller = Seller::factory()->create();
        $this->paidThenReturned($seller);
        $debt = SellerDebt::sole();
        $before = $debt->getAttributes();
        $this->travel(121)->days();
        try {
            $this->assertSame($before, $debt->fresh()->getAttributes());
            $this->assertSame(58410, app(PlatformFinanceService::class)->position()['sellerDebtMinor']);
            $this->assertSame(0, app(SellerPayoutRequestService::class)->availableBalance($seller));
            try {
                DB::table('sellers')->where('id', $seller->id)->delete();
                $this->fail('Financial foreign keys must restrict seller deletion.');
            } catch (QueryException) {
                $this->assertSame($before, $debt->fresh()->getAttributes());
            }
        } finally {
            $this->travelBack();
        }
    }

    public function test_a_direct_payout_is_refused_while_the_seller_has_a_debt(): void
    {
        $seller = Seller::factory()->create();
        $this->paidThenReturned($seller);
        $settlement = $this->earnings($seller);

        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin')
            ->postJson(route('admin.settlements.confirm', $settlement), ['amount_minor' => 58410, 'reference' => 'DIRECT-1', 'reason' => 'Seller asked for a direct transfer'])
            ->assertUnprocessable()->assertJsonValidationErrors('settlement');
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
    }

    public function test_a_partial_refund_can_invalidate_the_deduction_on_a_pending_payout(): void
    {
        $seller = Seller::factory()->create();
        $returned = $this->paidThenReturned($seller);
        $return = $returned->sellerOrder->parcelReturn;
        $return->forceFill(['financial_review_required' => true])->save();
        $settlement = $this->earnings($seller);
        $service = app(SellerPayoutRequestService::class);
        $payout = $service->request($seller, '584.10', (string) Str::uuid(), 'GCash 09171234567');
        $this->assertSame(58410, $payout->debt_deducted_minor);
        $admin = Admin::factory()->create(['role' => 'super_admin']);

        app(ParcelReturnService::class)->recordRefund($return, $admin, 'PARTIAL-REFUND', 10000,
            'Buyer', today()->toDateString(), reason: 'Buyer kept the remaining items.');
        $this->assertSame(9000, $service->outstandingDebtMinor($seller));

        $this->actingAs($admin, 'admin')->postJson(route('admin.seller-payouts.pay', $payout), ['reference' => 'STALE'])
            ->assertUnprocessable()->assertJsonValidationErrors('payout');
        $this->assertSame(SellerPayoutRequest::PENDING, $payout->fresh()->status);
        $this->assertNull($settlement->fresh()->paid_amount_minor);
        $this->assertSame(0, SellerDebt::query()->sole()->settled_minor);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'seller_payout_paid')->count());

        $service->reject($payout, $admin, 'Debt changed after the partial refund.');
        $replacement = $service->request($seller, '584.10', (string) Str::uuid(), $payout->payout_destination);
        $this->assertSame(9000, $replacement->debt_deducted_minor);
        $this->assertTrue($service->pay($replacement, $admin, 'CORRECT-NET'));
        $this->assertSame(58410, $settlement->fresh()->paid_amount_minor);
        $this->assertSame(9000, SellerDebt::query()->sole()->settled_minor);
    }

    public function test_direct_payout_rolls_back_if_its_audit_write_fails(): void
    {
        $settlement = $this->earnings(Seller::factory()->create());
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $action = route('admin.settlements.confirm', $settlement);
        $input = ['amount_minor' => 58410, 'reference' => 'DIRECT-AUDIT', 'reason' => 'Seller asked for a direct transfer'];
        $failAudit = true;
        OperationsAuditLog::creating(function (OperationsAuditLog $log) use (&$failAudit): void {
            if ($failAudit && $log->action === 'seller_direct_payout') {
                throw new \RuntimeException('Simulated audit write failure');
            }
        });

        $this->actingAs($admin, 'admin')->postJson($action, $input)->assertServerError();
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertNull($settlement->fresh()->paid_amount_minor);

        $failAudit = false;
        $this->postJson($action, $input)->assertOk();
        $this->postJson($action, $input)->assertConflict();
        $this->assertSame('DIRECT-AUDIT', OperationsAuditLog::query()->where('action', 'seller_direct_payout')->sole()->new_values['reference']);
    }

    public function test_only_a_super_admin_with_a_reason_can_use_the_direct_settlement_payout(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $input = ['amount_minor' => 58410, 'reference' => 'DIRECT-2'];

        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')
            ->postJson(route('admin.settlements.confirm', $settlement), $input + ['reason' => 'Seller asked for a direct transfer'])
            ->assertForbidden();

        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin');
        $this->postJson(route('admin.settlements.confirm', $settlement), $input)
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);

        $this->postJson(route('admin.settlements.confirm', $settlement), $input + ['reason' => 'Seller asked for a direct transfer'])
            ->assertOk()->assertJsonPath('status', SellerSettlement::PAID);
        $this->assertDatabaseHas('operations_audit_logs', ['action' => 'seller_direct_payout']);
    }

    private function submission(mixed $amount): array
    {
        return ['amount' => $amount, 'idempotency_key' => (string) Str::uuid(), 'destination' => 'GCash 09171234567'];
    }

    private function earnings(Seller $seller): SellerSettlement
    {
        // A9 example at 10%: (69900 - 5000) * 10% = 6490; Seller net = 69900 - 6490 - 5000 = 58410.
        $parent = Order::factory()->create(['discount_minor' => 5000]);
        $order = SellerOrder::factory()->status(OrderStatus::Completed)->create([
            'seller_id' => $seller->id, 'order_id' => $parent->id, 'subtotal_minor' => 69900,
        ]);
        $order->forceFill(['shipping_fee_minor' => 6500, 'voucher_discount_minor' => 5000, 'voucher_funding_source' => Voucher::FUNDING_SELLER])->save();
        $payment = Payment::factory()->create(['seller_order_id' => $order->id, 'amount_minor' => 71400]);
        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now(), 'collected_at' => now(), 'collected_amount_minor' => 71400])->save();
        $courier = Courier::factory()->create();
        $remittance = new CodRemittance;
        $remittance->forceFill([
            'seller_order_id' => $order->id, 'payment_id' => $payment->id, 'courier_id' => $courier->id,
            'logistics_provider_id' => $courier->logistics_provider_id, 'amount_minor' => 71400,
            'status' => CodRemittance::REMITTED_TO_LOGISTICS, 'received_amount_minor' => 71400, 'zefanya_received_at' => now(),
            'confirmed_at' => now(), 'confirmed_by_id' => $courier->logistics_provider_id,
        ])->save();
        $settlement = app(SellerSettlementService::class)->prepare($remittance);
        app(CommissionFinalizationService::class)->finalize($order);

        return $settlement->fresh();
    }

    public static function highValuePayoutAmounts(): array
    {
        return [['10000.00', 1000000], ['100000.00', 10000000], ['1000000.00', 100000000]];
    }

    #[DataProvider('highValuePayoutAmounts')]
    public function test_high_value_payouts_use_server_balances_and_authenticated_approval_identity(string $amount, int $minor): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        // Synthetic isolated balances exercise currency boundaries without changing commission rules.
        $base = 2 * $minor;
        $commission = intdiv($base, 10);
        $net = $base - $commission;
        $cash = $base + 6500;
        $settlement->sellerOrder->forceFill(['subtotal_minor' => $base + 5000, 'commission_minor' => $commission, 'seller_net_minor' => $net])->save();
        $settlement->payment->forceFill(['amount_minor' => $cash, 'collected_amount_minor' => $cash])->save();
        $settlement->codRemittance->forceFill(['amount_minor' => $cash, 'received_amount_minor' => $cash])->save();
        $settlement->forceFill(['collected_amount_minor' => $cash, 'commission_minor' => $commission, 'seller_payout_minor' => $net])->save();
        $other = Seller::factory()->create();
        $this->actingAs($seller, 'seller')->postJson(route('seller.payout-requests.store'), $this->submission($amount) + [
            'seller_id' => $other->id, 'amount_minor' => 1, 'status' => SellerPayoutRequest::PAID,
            'debt_deducted_minor' => $minor, 'seller_settlement_id' => 999999,
        ])->assertCreated()->assertJsonPath('seller_id', $seller->id)->assertJsonPath('amount_minor', $minor)
            ->assertJsonPath('debt_deducted_minor', 0)->assertJsonPath('status', SellerPayoutRequest::PENDING);
        $payout = SellerPayoutRequest::sole();
        $admin = Admin::factory()->create(['role' => 'admin']);
        $otherAdmin = Admin::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'admin')->postJson(route('admin.seller-payouts.pay', $payout), [
            'reference' => 'HIGH-VALUE-ATTESTATION', 'amount_minor' => PHP_INT_MAX,
            'seller_id' => $other->id, 'payout_request_id' => 999999, 'status' => SellerPayoutRequest::REJECTED,
            'debt_deducted_minor' => $minor, 'decided_by_admin_id' => $otherAdmin->id, 'paid_at' => '2000-01-01',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($minor, $payout->fresh()->amount_minor);
        $this->assertSame($minor, $settlement->fresh()->paid_amount_minor);
        $this->assertSame(0, $payout->fresh()->debt_deducted_minor);
        $this->assertSame($admin->id, (int) $payout->fresh()->decided_by_admin_id);
        $this->assertTrue($payout->fresh()->paid_at->isToday());
        $this->assertSame($net - $minor, app(SellerPayoutRequestService::class)->availableBalance($seller));
        $log = OperationsAuditLog::query()->where('action', 'seller_payout_paid')->sole();
        $this->assertSame($admin->id, (int) $log->actor_id);
    }

    public static function unauthorizedPayoutApprovers(): array
    {
        return [
            ['buyer', Buyer::class, []], ['seller', Seller::class, []], ['rider', Rider::class, []],
            ['logistics', LogisticsProvider::class, []], ['admin', Admin::class, ['role' => 'moderator']],
            ['admin', Admin::class, ['role' => 'support']],
        ];
    }

    #[DataProvider('unauthorizedPayoutApprovers')]
    public function test_unauthorized_roles_cannot_confirm_reject_or_directly_settle_payouts(string $guard, string $model, array $attributes): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $payout = app(SellerPayoutRequestService::class)->request($seller, '200.00', (string) Str::uuid());
        $actor = $guard === 'seller' ? $seller : $model::factory()->create($attributes);
        $this->actingAs($actor, $guard);
        $status = $guard === 'admin' ? 403 : 401;
        $this->postJson(route('admin.seller-payouts.pay', $payout), ['reference' => 'FORGED'])->assertStatus($status);
        $this->postJson(route('admin.seller-payouts.reject', $payout), ['reason' => 'Forged rejection'])->assertStatus($status);
        $this->postJson(route('admin.settlements.confirm', $settlement), [
            'amount_minor' => 58410, 'reference' => 'FORGED', 'reason' => 'Forged direct settlement request.',
        ])->assertStatus($status);
        $this->assertSame(SellerPayoutRequest::PENDING, $payout->fresh()->status);
        $this->assertNull($settlement->fresh()->paid_amount_minor);
        $this->assertDatabaseCount('operations_audit_logs', 0);
    }

    public function test_request_payment_audit_failure_rolls_back_settlement_and_debt_recovery(): void
    {
        $seller = Seller::factory()->create();
        $this->paidThenReturned($seller);
        $settlement = $this->earnings($seller);
        $payouts = app(SellerPayoutRequestService::class);
        $request = $payouts->request($seller, '200.00', (string) Str::uuid());
        $before = [$request->fresh()->getAttributes(), $settlement->fresh()->getAttributes(), SellerDebt::sole()->getAttributes()];
        $failAudit = true;
        OperationsAuditLog::creating(function (OperationsAuditLog $log) use (&$failAudit): void {
            if ($failAudit && $log->action === 'seller_payout_paid') {
                throw new \RuntimeException('Injected requested-payout audit failure.');
            }
        });
        $admin = Admin::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'admin')->postJson(route('admin.seller-payouts.pay', $request), ['reference' => 'AUDIT-FAILURE'])->assertServerError();
        $this->assertEquals($before, [$request->fresh()->getAttributes(), $settlement->fresh()->getAttributes(), SellerDebt::sole()->getAttributes()]);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'seller_payout_paid')->count());
        $failAudit = false;
        $this->assertTrue($payouts->pay($request, $admin, 'RETRY'));
        $this->assertSame(20000, SellerDebt::sole()->settled_minor);
    }

    public function test_a_direct_settlement_payout_is_counted_in_admin_and_seller_financial_records(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin')
            ->postJson(route('admin.settlements.confirm', $settlement), [
                'amount_minor' => 58410, 'reference' => 'DIRECT-REPORT', 'reason' => 'Seller requested a direct payment.',
            ])->assertOk();
        $finance = app(FinanceReportService::class);
        $ledger = $finance->ledger(FinanceReportService::period(null));
        $this->assertSame(58410, $finance->totals($ledger)[FinanceReportService::OUT]);
        $this->assertSame(58410, app(SellerEarningsService::class)->summary($seller)['paidWithdrawalsMinor']);
        $transactions = app(SellerTransactionService::class)->for($seller);
        $this->assertSame(58410, -$transactions->where('type', 'Withdrawal')->sum('amountMinor'));
        $this->assertSame(0, $transactions->first()['balanceAfterMinor']);
    }

    public function test_requested_and_direct_parts_of_the_same_settlement_are_reported_once_each(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $payouts = app(SellerPayoutRequestService::class);
        $request = $payouts->request($seller, '200.00', (string) Str::uuid());
        $payouts->pay($request, $admin, 'REQUESTED-PART');
        $this->actingAs($admin, 'admin')->postJson(route('admin.settlements.confirm', $settlement), [
            'amount_minor' => 38410, 'reference' => 'DIRECT-REMAINDER', 'reason' => 'Seller requested the remaining earnings.',
        ])->assertOk();
        $finance = app(FinanceReportService::class);
        $ledger = $finance->ledger(FinanceReportService::period(null))->where('type', 'seller_payout');
        $this->assertCount(2, $ledger);
        $this->assertSame(58410, $finance->totals($ledger)[FinanceReportService::OUT]);
        $this->assertSame(38410, $ledger->firstWhere('reference', 'DIRECT-REMAINDER')['amountMinor']);
        $this->assertSame(58410, app(SellerEarningsService::class)->summary($seller)['paidWithdrawalsMinor']);
        $transactions = app(SellerTransactionService::class)->for($seller);
        $this->assertSame(58410, -$transactions->where('type', 'Withdrawal')->sum('amountMinor'));
        $this->assertSame(0, $transactions->first()['balanceAfterMinor']);
    }

    public function test_mixed_direct_and_requested_cash_excludes_debt_recovery_exactly_once(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        SellerDebt::query()->forceCreate([
            'seller_id' => $seller->id, 'seller_order_id' => SellerOrder::factory()->create(['seller_id' => $seller->id])->id,
            'seller_settlement_id' => null, 'amount_minor' => 5000, 'reason' => SellerDebt::FAILED_DELIVERY,
        ]);
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $payouts = app(SellerPayoutRequestService::class);
        $payouts->pay($payouts->request($seller, '200.00', (string) Str::uuid()), $admin, 'REQUEST-WITH-DEBT');
        app(SellerSettlementService::class)->confirmPayout($settlement, $admin, 38410, 'DIRECT-NET', 'Seller requested remaining funds.');
        $finance = app(FinanceReportService::class);
        $this->assertSame(53410, $finance->totals($finance->ledger(FinanceReportService::period(null)))[FinanceReportService::OUT]);
        $this->assertSame(58410, app(SellerEarningsService::class)->summary($seller)['paidWithdrawalsMinor']);
        $this->assertSame(5000, SellerDebt::sole()->settled_minor);
        $this->assertSame(0, $payouts->outstandingDebtMinor($seller));
    }

    public function test_a_request_only_payout_is_not_reported_again_as_a_direct_settlement(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $payouts = app(SellerPayoutRequestService::class);
        $payouts->pay($payouts->request($seller, '584.10', (string) Str::uuid()), Admin::factory()->create(['role' => 'admin']), 'REQUEST-ONLY');
        $finance = app(FinanceReportService::class);
        $ledger = $finance->ledger(FinanceReportService::period(null))->where('type', 'seller_payout');
        $this->assertCount(1, $ledger);
        $this->assertSame(58410, $finance->totals($ledger)[FinanceReportService::OUT]);
        $this->assertSame(0, $settlement->fresh()->directPayoutMinor());
        $this->assertSame(58410, app(SellerEarningsService::class)->summary($seller)['paidWithdrawalsMinor']);
    }

    public function test_direct_remainder_is_reported_in_its_own_month_after_an_earlier_requested_part(): void
    {
        $current = now();
        $seller = Seller::factory()->create();
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $this->travelTo($current->copy()->subMonthNoOverflow()->day(10));
        try {
            $settlement = $this->earnings($seller);
            $payouts = app(SellerPayoutRequestService::class);
            $payouts->pay($payouts->request($seller, '200.00', (string) Str::uuid()), $admin, 'PREVIOUS-MONTH');
            $previousPeriod = FinanceReportService::period(null);
            $this->travelTo($current);
            app(SellerSettlementService::class)->confirmPayout($settlement, $admin, 38410, 'CURRENT-MONTH', 'Seller requested remaining funds.');
            $finance = app(FinanceReportService::class);
            $this->assertSame(20000, $finance->totals($finance->ledger($previousPeriod))[FinanceReportService::OUT]);
            $this->assertSame(38410, $finance->totals($finance->ledger(FinanceReportService::period(null)))[FinanceReportService::OUT]);
        } finally {
            $this->travelBack();
        }
    }

    public function test_seller_earnings_show_debt_and_paid_withdrawals_and_platform_position_counts_the_debt(): void
    {
        $seller = Seller::factory()->create();
        $this->paidThenReturned($seller);

        $summary = app(SellerEarningsService::class)->summary($seller);
        $this->assertSame(58410, $summary['debtMinor']);
        $this->assertSame(58410, $summary['paidWithdrawalsMinor']);
        $this->assertSame(58410, app(PlatformFinanceService::class)->position()['sellerDebtMinor']);

        $this->actingAs($seller, 'seller')->get(route('seller.earnings.index'))
            ->assertOk()->assertSee('Debt to Zefanya')->assertSee('Paid withdrawals')->assertSee('−₱584.10');
    }
}
