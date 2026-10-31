<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerPayoutAllocation;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Services\Seller\SellerPayoutRequestService;
use App\Services\Seller\SellerSettlementService;
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
        return ['minimum' => ['100.00', 10000, 51655], 'entire balance' => ['616.55', 61655, 0]];
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
            'below minimum' => ['99.99'], 'above balance' => ['616.56'], 'zero' => ['0'],
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
        $this->assertSame(61655, app(SellerPayoutRequestService::class)->availableBalance($seller));
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
        $this->assertSame(51655, app(SellerPayoutRequestService::class)->availableBalance($seller));
    }

    public function test_sel_earn_007_replays_a_duplicate_submission_without_reserving_twice(): void
    {
        $seller = Seller::factory()->create();
        $this->earnings($seller);
        $submission = $this->submission('100.00');
        $first = $this->actingAs($seller, 'seller')->postJson(route('seller.payout-requests.store'), $submission)->assertCreated();

        $this->postJson(route('seller.payout-requests.store'), $submission)
            ->assertOk()->assertJsonPath('id', $first->json('id'));

        $this->assertDatabaseCount('seller_payout_requests', 1);
        $this->assertDatabaseCount('seller_payout_allocations', 1);
        $this->assertSame(10000, (int) SellerPayoutAllocation::query()->sum('amount_minor'));
        $this->assertSame(51655, app(SellerPayoutRequestService::class)->availableBalance($seller));
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
        $this->assertSame(51655, app(SellerPayoutRequestService::class)->availableBalance($seller));
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
        $this->assertSame(51655, $service->availableBalance($seller));
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
        $this->assertSame(51655, $service->availableBalance($seller));
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

        $this->assertSame(61655, $service->availableBalance($seller));
        $this->assertNull($payout->fresh()->pending_seller_id);
        $this->assertNotNull($payout->fresh()->cancelled_at);
        $this->assertDatabaseCount('seller_payout_allocations', 1);
        // A late retry of the cancelled submission must not create a new reservation.
        $this->postJson(route('seller.payout-requests.store'), $submission)
            ->assertOk()->assertJsonPath('status', SellerPayoutRequest::CANCELLED);
        $this->assertSame(61655, $service->availableBalance($seller));
        $this->postJson(route('seller.payout-requests.store'), $this->submission('616.55'))->assertCreated();
        $this->assertSame(0, $service->availableBalance($seller));
        $this->assertDatabaseCount('seller_payout_requests', 2);
    }

    public function test_reservations_can_span_multiple_settlements(): void
    {
        $seller = Seller::factory()->create();
        $first = $this->earnings($seller);
        $second = $this->earnings($seller);

        $this->actingAs($seller, 'seller')->postJson(route('seller.payout-requests.store'), $this->submission('700'))->assertCreated();

        $this->assertDatabaseHas('seller_payout_allocations', ['seller_settlement_id' => $first->id, 'amount_minor' => 61655]);
        $this->assertDatabaseHas('seller_payout_allocations', ['seller_settlement_id' => $second->id, 'amount_minor' => 8345]);
        $this->assertSame(53310, app(SellerPayoutRequestService::class)->availableBalance($seller));
    }

    public function test_historical_finalized_commissions_are_used_without_recalculation(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        // Historical 10% snapshot: gross 69900, seller discount 5000, commission 6990, net 57910.
        $order = $settlement->sellerOrder;
        $order->forceFill(['commission_bps' => 1000, 'commission_minor' => 6990, 'seller_net_minor' => 57910])->save();
        $settlement->forceFill(['commission_bps' => 1000, 'commission_minor' => 6990, 'seller_payout_minor' => 57910])->save();
        $before = $order->getRawOriginal();

        $this->actingAs($seller, 'seller')->postJson(route('seller.payout-requests.store'), $this->submission('579.10'))->assertCreated();

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
        $this->assertSame(51655, app(SellerPayoutRequestService::class)->availableBalance($owner));
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
        $this->assertSame(51655, $service->availableBalance($first));
        $this->assertSame(51655, $service->availableBalance($second));
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
            'already_paid' => $settlement->forceFill(['status' => SellerSettlement::PAID, 'paid_amount_minor' => 61655, 'paid_at' => now()])->save(),
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
        $admin = Admin::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'admin');
        $input = ['amount_minor' => 61655, 'reference' => 'RULE09-DIRECT'];

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

    public function test_admin_pays_a_full_request_and_the_seller_is_told(): void
    {
        $seller = Seller::factory()->create(['business_name' => 'EdGaming']);
        $settlement = $this->earnings($seller);
        $service = app(SellerPayoutRequestService::class);
        $payout = $service->request($seller, '616.55', (string) Str::uuid(), 'GCash 09171234567');
        $admin = Admin::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'admin')->get(route('admin.seller-payouts.index'))
            ->assertOk()->assertSee('EdGaming')->assertSee('₱616.55')->assertSee('GCash 09171234567');

        $this->post(route('admin.seller-payouts.pay', $payout), [])->assertSessionHasErrors('reference');
        $this->post(route('admin.seller-payouts.pay', $payout), ['reference' => 'BDO-777'])->assertSessionHasNoErrors()->assertSessionHas('success');

        $payout->refresh();
        $this->assertSame(SellerPayoutRequest::PAID, $payout->status);
        $this->assertNull($payout->pending_seller_id);
        $this->assertSame('BDO-777', $payout->payout_reference);
        $this->assertSame($admin->id, (int) $payout->decided_by_admin_id);
        $this->assertSame(SellerSettlement::PAID, $settlement->fresh()->status);
        $this->assertSame(61655, $settlement->fresh()->paid_amount_minor);
        $this->assertSame(0, $service->availableBalance($seller));
        $this->assertSame('seller_payout_paid', $seller->notifications()->sole()->data['type']);
        $this->assertSame('₱616.55', OperationsAuditLog::query()->where('action', 'seller_payout_paid')->sole()->new_values['amount']);

        $this->post(route('admin.seller-payouts.pay', $payout), ['reference' => 'BDO-777'])
            ->assertSessionHas('success', 'This payout was already recorded.');
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
        $this->assertSame(51655, $service->availableBalance($seller));

        try {
            $service->request($seller, '516.56', (string) Str::uuid());
            $this->fail('A request above what is left must be refused.');
        } catch (ValidationException) {
            $this->assertSame(51655, $service->availableBalance($seller));
        }

        $service->pay($service->request($seller, '516.55', (string) Str::uuid()), $admin, 'PART-2');

        $this->assertSame(SellerSettlement::PAID, $settlement->fresh()->status);
        $this->assertSame(61655, $settlement->fresh()->paid_amount_minor);
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
            $direct->confirmPayout($settlement->fresh(), $admin, 61655, 'TOO-MUCH');
            $this->fail('Paying the full amount again must be refused.');
        } catch (ValidationException) {
            $this->assertSame(10000, $settlement->fresh()->paid_amount_minor);
        }

        $direct->confirmPayout($settlement->fresh(), $admin, 51655, 'THE-REST');
        $this->assertSame(SellerSettlement::PAID, $settlement->fresh()->status);
        $this->assertSame(61655, $settlement->fresh()->paid_amount_minor);
    }

    public function test_admin_rejects_a_request_and_the_earnings_come_back(): void
    {
        $seller = Seller::factory()->create();
        $settlement = $this->earnings($seller);
        $service = app(SellerPayoutRequestService::class);
        $payout = $service->request($seller, '616.55', (string) Str::uuid());
        $this->assertSame(0, $service->availableBalance($seller));

        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin');
        $this->post(route('admin.seller-payouts.reject', $payout), [])->assertSessionHasErrors('reason');
        $this->post(route('admin.seller-payouts.reject', $payout), ['reason' => 'GCash number not registered'])->assertSessionHas('success');

        $this->assertSame(SellerPayoutRequest::REJECTED, $payout->fresh()->status);
        $this->assertSame(61655, $service->availableBalance($seller));
        $this->assertNull($settlement->fresh()->paid_amount_minor);
        $this->assertSame('seller_payout_rejected', $seller->notifications()->sole()->data['type']);

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

        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin');
        $input = ['amount_minor' => 61655, 'reference' => 'TOO-EARLY'];
        $this->postJson(route('admin.settlements.confirm', $settlement), $input)
            ->assertUnprocessable()->assertJsonValidationErrors('settlement');
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);

        $settlement->codRemittance->forceFill(['zefanya_received_at' => now()])->save();
        $this->assertSame(61655, $service->availableBalance($seller));
        $this->postJson(route('admin.settlements.confirm', $settlement), $input)
            ->assertOk()->assertJsonPath('status', SellerSettlement::PAID);
    }

    public function test_paying_an_unreserved_settlement_does_not_spend_a_pending_reservation(): void
    {
        $seller = Seller::factory()->create();
        $this->earnings($seller);
        $unreserved = $this->earnings($seller);
        app(SellerPayoutRequestService::class)->request($seller, '100', (string) Str::uuid());

        app(SellerSettlementService::class)->confirmPayout($unreserved, Admin::factory()->create(), 61655, 'UNRESERVED');

        $this->assertSame(51655, app(SellerPayoutRequestService::class)->availableBalance($seller));
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
        $this->assertSame(61655, app(SellerPayoutRequestService::class)->availableBalance($seller));
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
            ->assertRedirect();

        $this->assertDatabaseCount('seller_payout_requests', 0);
    }

    public function test_browser_form_shows_available_pending_and_cancelled_history(): void
    {
        $seller = Seller::factory()->create();
        $this->earnings($seller);
        $response = $this->actingAs($seller, 'seller')->get(route('seller.payout-requests.index'))
            ->assertOk()->assertSee('₱616.55')->assertSee('name="idempotency_key"', false);

        $this->post(route('seller.payout-requests.store'), ['amount' => '100', 'idempotency_key' => $response->viewData('idempotencyKey'), 'destination' => 'GCash 09171234567'])
            ->assertRedirect(route('seller.payout-requests.index'));
        $this->get(route('seller.payout-requests.index'))->assertSee('₱516.55')->assertSee('A payout request is already pending');
        $payout = SellerPayoutRequest::query()->sole();
        $this->delete(route('seller.payout-requests.destroy', $payout))->assertRedirect(route('seller.payout-requests.index'));
        $this->get(route('seller.payout-requests.index'))->assertSee('₱616.55')->assertSee('Cancelled');
    }

    private function submission(mixed $amount): array
    {
        return ['amount' => $amount, 'idempotency_key' => (string) Str::uuid(), 'destination' => 'GCash 09171234567'];
    }

    private function earnings(Seller $seller): SellerSettlement
    {
        // Official A9 example: (69900 - 5000) * 5% = 3245; Seller net = 61655.
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
}
