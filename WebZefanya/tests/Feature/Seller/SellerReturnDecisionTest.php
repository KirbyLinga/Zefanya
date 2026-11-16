<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Enums\PaymentStatus;
use App\Http\Resources\Mobile\RiderParcelResource;
use App\Http\Resources\Mobile\ShipmentResource;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsReturnCharge;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\FinancialReference;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Notifications\Buyer\ReturnDisputeResolved;
use App\Notifications\Buyer\ReturnRequestDecided;
use App\Notifications\Seller\ReturnDisputed;
use App\Services\Admin\PlatformFinanceService;
use App\Services\Logistics\ParcelHandoffService;
use App\Services\Buyer\BuyerShipmentConfirmation;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Logistics\ReturnChargeService;
use App\Services\Seller\SellerPayoutRequestService;
use App\Services\Seller\SellerSettlementService;
use App\Services\Shared\CommissionFinalizationService;
use App\Services\Shared\FinancialReferenceRegistry;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/** SEL-RET-001 to 004: reason, description, photos, 3-day deadline, Approve or Reject. */
class SellerReturnDecisionTest extends TestCase
{
    use LazilyRefreshDatabase;

    private SellerOrder $parcel;

    private Seller $seller;

    private Buyer $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'UTC'));

        $this->buyer = Buyer::factory()->create();
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create([
            'order_id' => Order::factory()->create(['buyer_id' => $this->buyer->id])->id,
            'seller_id' => $this->seller->id,
        ]);
        StatusHistory::query()->create([
            'seller_order_id' => $this->parcel->id, 'from_status' => OrderStatus::OutForDelivery,
            'to_status' => OrderStatus::Delivered, 'actor_type' => 'rider', 'source' => 'web',
        ]);
        $payment = Payment::factory()->create(['seller_order_id' => $this->parcel->id, 'amount_minor' => 10000]);
        $payment->forceFill(['status' => PaymentStatus::Paid, 'paid_at' => now()])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param array<string, mixed> $extra */
    private function requestReturn(array $extra = []): TestResponse
    {
        app(BuyerShipmentConfirmation::class)->confirm($this->parcel->order, $this->buyer, $this->parcel->id);

        return $this->actingAs($this->buyer, 'buyer')->post(
            route('buyer.orders.requestReturn', [$this->parcel->order, $this->parcel]),
            $extra + ['reason' => 'Item arrived damaged', 'description' => 'The delivered item arrived damaged.', 'photos' => [UploadedFile::fake()->image('damage.jpg')]],
            ['Accept' => 'application/json'],
        );
    }

    private function record(): ParcelReturn
    {
        $this->requestReturn()->assertCreated();

        return ParcelReturn::sole();
    }

    /** An ordinary refund needs an approved return (Audit 1.13 refund policy). */
    private function approvedRecord(): ParcelReturn
    {
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();

        return $record->fresh();
    }

    private function reject(ParcelReturn $record, ?string $reason = 'The item was fine when shipped.'): TestResponse
    {
        return $this->actingAs($this->seller, 'seller')->post(route('seller.returns.reject', $record), ['reason' => $reason]);
    }

    public function test_buyer_can_add_a_description_and_photos(): void
    {
        $this->requestReturn([
            'description' => 'The box was crushed and the zipper is broken.',
            'photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png')],
        ])->assertCreated();

        $record = ParcelReturn::sole();
        $this->assertSame('The box was crushed and the zipper is broken.', $record->description);
        $this->assertCount(2, $record->photos);
        Storage::disk('local')->assertExists($record->photos[0]);
        $this->assertSame('2026-11-04 00:00:00', $record->seller_response_due_at->format('Y-m-d H:i:s'));
        $this->assertTrue($record->awaitingSellerDecision());
    }

    public function test_buyer_photo_rules(): void
    {
        foreach ([
            [UploadedFile::fake()->image('a.gif')],
            [UploadedFile::fake()->image('a.png')->size(5121)],
            array_map(fn ($i) => UploadedFile::fake()->image("{$i}.png"), range(1, 6)),
        ] as $photos) {
            $this->requestReturn(['photos' => $photos])->assertStatus(422);
        }

        $this->assertSame(0, ParcelReturn::count());
    }

    public function test_seller_sees_reason_description_photos_and_deadline(): void
    {
        $this->requestReturn([
            'description' => 'The box was crushed.',
            'photos' => [UploadedFile::fake()->image('a.jpg')],
        ]);
        $record = ParcelReturn::sole();

        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))
            ->assertOk()
            ->assertSee('Item arrived damaged')
            ->assertSee('The box was crushed.')
            ->assertSee(route('seller.returns.photo', [$record, 0]), false)
            ->assertSee('Respond by Nov 4, 2026 8:00 AM')
            ->assertSee('action="'.route('seller.returns.approve', $record).'"', false)
            ->assertSee('action="'.route('seller.returns.reject', $record).'"', false);

        $this->get(route('seller.returns.photo', [$record, 0]))->assertOk();
        $this->get(route('seller.returns.photo', [$record, 1]))->assertNotFound();
    }

    public function test_other_sellers_cannot_see_photos_or_decide_and_guests_are_redirected(): void
    {
        $this->requestReturn(['photos' => [UploadedFile::fake()->image('a.jpg')]]);
        $record = ParcelReturn::sole();

        $this->actingAs(Seller::factory()->create(['status' => 'approved']), 'seller');
        $this->get(route('seller.returns.photo', [$record, 0]))->assertForbidden();
        $this->post(route('seller.returns.approve', $record))->assertForbidden();
        $this->post(route('seller.returns.reject', $record), ['reason' => 'Not my order at all'])->assertForbidden();
        $this->get(route('seller.returns.index'))->assertOk()->assertDontSee('Item arrived damaged');

        $this->assertTrue($record->fresh()->awaitingSellerDecision());

        auth('seller')->logout();
        $this->get(route('seller.returns.photo', [$record, 0]))->assertRedirect();
    }

    public function test_seller_approves(): void
    {
        Notification::fake();
        $record = $this->record();

        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertRedirect()->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame(ParcelReturn::APPROVED, $record->seller_decision);
        $this->assertNotNull($record->seller_decided_at);
        $this->assertSame(ParcelReturnStatus::Requested, $record->status);
        $this->assertFalse($record->awaitingSellerDecision());
        $this->assertTrue($record->isActive());
        $this->assertSame(OrderStatus::Completed, $this->parcel->fresh()->status);
        $this->assertTrue($record->events()->where('event_key', 'seller_approved')->where('actor_type', 'seller')->exists());
        Notification::assertSentToTimes($this->buyer, ReturnRequestDecided::class, 1);
    }

    /** @return array<string, array{0: string|null, 1: bool}> */
    public static function rejectReasons(): array
    {
        return [
            '10 characters' => [str_repeat('a', 10), true],
            '300 characters' => [str_repeat('a', 300), true],
            '9 characters' => [str_repeat('a', 9), false],
            '301 characters' => [str_repeat('a', 301), false],
            'only spaces' => [str_repeat(' ', 12), false],
            'missing' => [null, false],
        ];
    }

    #[DataProvider('rejectReasons')]
    public function test_reject_reason_must_be_10_to_300_characters(?string $reason, bool $accepted): void
    {
        $record = $this->record();

        $response = $this->reject($record, $reason);

        if ($accepted) {
            $response->assertSessionHasNoErrors();
            $this->assertSame($reason, $record->fresh()->seller_decision_reason);
            $this->assertSame(ParcelReturnStatus::Rejected, $record->fresh()->status);
        } else {
            $response->assertSessionHasErrors('reason');
            $this->assertTrue($record->fresh()->awaitingSellerDecision());
        }
    }

    public function test_rejecting_closes_the_request_tells_the_buyer_and_frees_the_order(): void
    {
        Notification::fake();
        $record = $this->record();

        $this->reject($record)->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertFalse($record->isActive());
        $this->assertFalse($this->parcel->fresh()->hasActiveReturn());
        Notification::assertSentTo($this->buyer, ReturnRequestDecided::class, fn ($notification) => str_contains(
            $notification->toArray($this->buyer)['message'],
            'The item was fine when shipped.',
        ));

        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.orders.show', $this->parcel->order))
            ->assertOk()
            ->assertSee('Return request rejected')
            ->assertSee('The item was fine when shipped.')
            ->assertDontSee('Order Received');
    }

    public function test_a_rejected_return_does_not_create_another_on_retry(): void
    {
        $record = $this->record();
        $this->reject($record);

        $this->requestReturn(['reason' => 'Other'])->assertOk();
        $this->assertSame(1, ParcelReturn::count());
        $this->assertSame('Item arrived damaged', $record->fresh()->reason);

        Carbon::setTestNow(now()->addDays(8));
        $this->artisan('orders:auto-complete-receipts')->assertSuccessful();

        $this->assertSame(OrderStatus::Completed, $this->parcel->fresh()->status);
    }

    public function test_an_open_return_is_auto_approved_without_changing_confirmed_receipt(): void
    {
        $record = $this->record();

        Carbon::setTestNow(now()->addDays(8));
        $this->artisan('returns:auto-approve-overdue')->assertSuccessful();
        $this->artisan('orders:auto-complete-receipts')->assertSuccessful();

        $this->assertSame(ParcelReturn::AUTO_APPROVED, $record->fresh()->seller_decision);
        $this->assertSame(OrderStatus::Completed, $this->parcel->fresh()->status);
    }

    public function test_a_decision_is_final(): void
    {
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record));

        $this->reject($record)->assertSessionHasErrors('return');
        $this->post(route('seller.returns.approve', $record))->assertSessionHasErrors('return');

        $this->assertSame(ParcelReturn::APPROVED, $record->fresh()->seller_decision);
    }

    public function test_deadline_boundary(): void
    {
        $record = $this->record();

        Carbon::setTestNow(Carbon::parse('2026-11-03 23:59:59', 'UTC'));
        $this->artisan('returns:auto-approve-overdue')->assertSuccessful();
        $this->assertTrue($record->fresh()->awaitingSellerDecision());

        Carbon::setTestNow(Carbon::parse('2026-11-04 00:00:00', 'UTC'));
        $this->reject($record)->assertSessionHasErrors('return');
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasErrors('return');
        $this->get(route('seller.returns.index'))->assertOk()
            ->assertSee('being approved automatically')
            ->assertDontSee('action="'.route('seller.returns.reject', $record).'"', false);

        $this->artisan('returns:auto-approve-overdue')->assertSuccessful();
        $this->assertSame(ParcelReturn::AUTO_APPROVED, $record->fresh()->seller_decision);
        $this->assertTrue($record->fresh()->events()->where('event_key', 'seller_approved')->where('actor_type', 'system')->exists());

        $this->get(route('seller.returns.index'))->assertOk()->assertSee('Approved automatically');
    }

    public function test_auto_approve_runs_once_and_notifies_once(): void
    {
        Notification::fake();
        $this->record();

        Carbon::setTestNow(now()->addDays(4));
        $this->artisan('returns:auto-approve-overdue')->assertSuccessful();
        $this->artisan('returns:auto-approve-overdue')->assertSuccessful();

        Notification::assertSentToTimes($this->buyer, ReturnRequestDecided::class, 1);
    }

    public function test_logistics_cannot_move_a_buyer_return_until_the_seller_decides(): void
    {
        $record = $this->record();
        $provider = LogisticsProvider::factory()->create();
        $service = app(ParcelReturnService::class);

        try {
            $service->reverseManifest($record, $provider, 1);
            $this->fail('A pending or unowned return must not be routed.');
        } catch (\Throwable $exception) {
            $this->assertTrue($record->fresh()->awaitingSellerDecision());
        }

        $reflection = new \ReflectionMethod($service, 'routeReady');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The Seller has not decided');
        $reflection->invoke($service, $record->fresh());
    }

    public function test_handoff_refuses_a_pending_buyer_return_until_the_seller_decides(): void
    {
        $record = $this->record();
        $service = new ParcelHandoffService;
        $assertOpen = new \ReflectionMethod($service, 'assertParcelOpen');

        try {
            $assertOpen->invoke($service, $this->parcel->fresh());
            $this->fail('A pending buyer return must not be handed off.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('has not decided', $exception->getMessage());
        }

        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record));

        try {
            $assertOpen->invoke($service, $this->parcel->fresh());
        } catch (ValidationException $exception) {
            $this->assertStringNotContainsString('has not decided', $exception->getMessage());
        }
    }

    public function test_a_failed_delivery_return_needs_no_seller_decision(): void
    {
        $parcel = SellerOrder::factory()->status(OrderStatus::DeliveryFailed)->create([
            'order_id' => Order::factory()->create(['buyer_id' => $this->buyer->id])->id,
            'seller_id' => $this->seller->id,
        ]);
        $record = new ParcelReturn;
        $record->forceFill([
            'seller_order_id' => $parcel->id, 'status' => ParcelReturnStatus::Requested,
            'reason' => 'Two failed delivery attempts.', 'requested_at' => now(),
        ])->save();

        $this->assertFalse($record->awaitingSellerDecision());

        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))
            ->assertOk()
            ->assertDontSee('action="'.route('seller.returns.approve', $record).'"', false);
        $this->post(route('seller.returns.approve', $record))->assertSessionHasErrors('return');
    }

    private function escalate(): TestResponse
    {
        return $this->actingAs($this->buyer, 'buyer')->post(
            route('buyer.orders.escalateReturn', [$this->parcel->order, $this->parcel]),
            ['reason' => 'The photos show the damage the seller denied.'],
        );
    }

    public function test_sel_ret_005_buyer_escalates_a_rejection_once_and_the_seller_is_locked_out(): void
    {
        Notification::fake();
        $record = $this->record();
        $this->reject($record);

        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.orders.show', $this->parcel->order))
            ->assertOk()
            ->assertSee('Escalate')
            ->assertSee(route('buyer.orders.escalateReturn', [$this->parcel->order, $this->parcel]), false);

        $this->escalate()->assertRedirect()->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame(ParcelReturnStatus::InDispute, $record->status);
        $this->assertNotNull($record->escalated_at);
        $this->assertTrue($record->inDispute());
        $this->assertTrue($this->parcel->fresh()->hasActiveReturn());
        $this->assertTrue($record->events()->where('event_key', 'buyer_escalated')->exists());
        Notification::assertSentTo($this->seller, ReturnDisputed::class);

        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.orders.show', $this->parcel->order))
            ->assertOk()
            ->assertSee('In Dispute')
            ->assertDontSee('>Escalate</button>', false)
            ->assertDontSee('Order Received');

        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))
            ->assertOk()
            ->assertSee('You cannot change it')
            ->assertDontSee('action="'.route('seller.returns.approve', $record).'"', false)
            ->assertDontSee('action="'.route('seller.returns.reject', $record).'"', false);
        $this->post(route('seller.returns.approve', $record))->assertSessionHasErrors('return');
        $this->post(route('seller.returns.reject', $record), ['reason' => 'Changing my mind now'])->assertSessionHasErrors('return');

        $this->escalate()->assertSessionHasErrors('return');
        $this->assertSame(1, $record->events()->where('event_key', 'buyer_escalated')->count());
        $this->assertSame(ParcelReturnStatus::InDispute, $record->fresh()->status);
    }

    private function buyerOrderPage(): TestResponse
    {
        return $this->actingAs($this->buyer, 'buyer')->get(route('buyer.orders.show', $this->parcel->order))->assertOk();
    }

    public function test_buy_ret_005_a_rejection_shows_the_sellers_reason_and_an_escalate_button(): void
    {
        Notification::fake();
        $this->reject($this->record(), 'The <b>seal</b> was intact.');

        $this->buyerOrderPage()
            ->assertSee('Return request rejected')
            ->assertSee('reason:')
            ->assertSee('The &lt;b&gt;seal&lt;/b&gt; was intact.', false)
            ->assertSee('>Escalate</button>', false)
            ->assertSee('action="'.route('buyer.orders.escalateReturn', [$this->parcel->order, $this->parcel]).'"', false);
    }

    public function test_buy_ret_005_the_reason_stays_visible_during_and_after_a_dispute(): void
    {
        Notification::fake();
        $record = $this->record();
        $this->reject($record);
        $this->escalate();

        $this->buyerOrderPage()
            ->assertSee('In Dispute')
            ->assertSee('The item was fine when shipped.')
            ->assertDontSee('>Escalate</button>', false);

        app(ParcelReturnService::class)->resolveDispute($record, Admin::factory()->create(['role' => 'super_admin']), ParcelReturn::DISPUTE_UPHELD, 'The photos do not show any damage.');

        $this->buyerOrderPage()
            ->assertSee('The item was fine when shipped.')
            ->assertSee('The photos do not show any damage.')
            ->assertDontSee('>Escalate</button>', false);
    }

    public function test_buy_ret_005_an_overturned_rejection_tells_the_buyer_it_is_approved(): void
    {
        Notification::fake();
        $record = $this->record();
        $this->reject($record);
        $this->escalate();
        app(ParcelReturnService::class)->resolveDispute($record, Admin::factory()->create(['role' => 'super_admin']), ParcelReturn::DISPUTE_OVERTURNED, 'The photos match the description.');

        $this->buyerOrderPage()
            ->assertSee('Return request approved')
            ->assertSee('An admin overturned')
            ->assertSee('The photos match the description.')
            ->assertSee('A rider will be assigned')
            ->assertDontSee('>Escalate</button>', false);
    }

    public function test_buy_ret_005_an_open_request_shows_the_decision_deadline_in_manila_time(): void
    {
        Notification::fake();
        $record = $this->record();

        $this->buyerOrderPage()
            ->assertSee('Return request open')
            ->assertSee($record->seller_response_due_at->timezone('Asia/Manila')->format('M j, Y g:i A'))
            ->assertDontSee('>Escalate</button>', false);
    }

    public function test_buy_ret_005_a_missed_deadline_says_it_was_approved_automatically(): void
    {
        Notification::fake();
        $record = $this->record();
        $record->forceFill(['seller_decision' => ParcelReturn::AUTO_APPROVED])->save();

        $this->buyerOrderPage()
            ->assertSee('Return request approved')
            ->assertSee('approved automatically');
    }

    public function test_buy_ret_005_a_finished_return_and_its_refund_stay_on_the_page(): void
    {
        Notification::fake();
        $record = $this->record();
        $record->forceFill(['status' => ParcelReturnStatus::Completed, 'seller_decision' => ParcelReturn::APPROVED, 'financial_review_required' => true])->save();

        $this->buyerOrderPage()->assertSee('Return completed')->assertSee('Your refund is being reviewed.');

        $record->forceFill(['refunded_at' => now(), 'refund_reference' => 'GCASH-5512'])->save();

        $this->buyerOrderPage()->assertSee('Refund recorded')->assertSee('GCASH-5512')->assertDontSee('Your refund is being reviewed.');
    }

    public function test_buy_ret_005_a_failed_delivery_return_is_not_shown_as_the_buyers_request(): void
    {
        Notification::fake();
        $record = $this->record();
        $record->forceFill(['seller_response_due_at' => null, 'seller_decision' => null])->save();

        $this->buyerOrderPage()
            ->assertSee('Return in progress')
            ->assertDontSee('Return request open')
            ->assertDontSee('>Escalate</button>', false);
    }

    /** @return array<string, array{0: ?string}> */
    public static function badEscalationReasons(): array
    {
        return ['missing' => [null], 'too short' => ['Too short'], 'only spaces' => ['               '], 'too long' => [str_repeat('a', 1001)]];
    }

    #[DataProvider('badEscalationReasons')]
    public function test_buy_ret_005_escalating_needs_a_real_reason(?string $reason): void
    {
        Notification::fake();
        $record = $this->record();
        $this->reject($record);

        $this->actingAs($this->buyer, 'buyer')
            ->post(route('buyer.orders.escalateReturn', [$this->parcel->order, $this->parcel]), ['reason' => $reason])
            ->assertSessionHasErrors();

        $this->assertSame(ParcelReturnStatus::Rejected, $record->fresh()->status);
    }

    public function test_buy_ret_005_only_the_orders_buyer_can_escalate_and_only_a_rejection(): void
    {
        Notification::fake();
        $record = $this->record();
        $url = route('buyer.orders.escalateReturn', [$this->parcel->order, $this->parcel]);
        $payload = ['reason' => 'The photos show the damage the seller denied.'];

        // Nothing to escalate yet: the seller has not answered.
        $this->actingAs($this->buyer, 'buyer')->post($url, $payload)->assertSessionHasErrors('return');
        $this->assertSame(ParcelReturnStatus::Requested, $record->fresh()->status);

        $this->reject($record);

        $this->actingAs(Buyer::factory()->create(), 'buyer')->post($url, $payload)->assertNotFound();
        $this->assertSame(ParcelReturnStatus::Rejected, $record->fresh()->status);
    }

    public function test_sel_ret_005_admin_can_keep_the_rejection_or_let_the_return_proceed(): void
    {
        Notification::fake();
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $record = $this->record();
        $this->reject($record);
        $this->escalate();

        $this->actingAs($admin, 'admin')->get(route('admin.returns.index'))
            ->assertOk()
            ->assertSee('In Dispute')
            ->assertSee(route('admin.returns.dispute', $record), false);

        $this->post(route('admin.returns.dispute', $record), [
            'outcome' => 'upheld',
            'note' => 'The photos do not show the claimed damage.',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame(ParcelReturnStatus::Rejected, $record->status);
        $this->assertSame(ParcelReturn::DISPUTE_UPHELD, $record->dispute_outcome);
        $this->assertNotNull($record->dispute_resolved_at);
        $this->assertFalse($record->canBuyerEscalate());
        $this->assertFalse($this->parcel->fresh()->hasActiveReturn());
        Notification::assertSentTo($this->buyer, ReturnDisputeResolved::class);

        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.orders.show', $this->parcel->order))
            ->assertOk()
            ->assertSee('kept the rejection')
            ->assertDontSee('>Escalate</button>', false)
            ->assertDontSee('Order Received');

        $this->escalate()->assertSessionHasErrors('return');
        $this->actingAs($admin, 'admin')->post(route('admin.returns.dispute', $record), [
            'outcome' => 'overturned',
            'note' => 'Trying to reopen a closed dispute.',
        ])->assertSessionHasErrors('return');
    }

    public function test_sel_ret_005_admin_can_overturn_the_rejection(): void
    {
        Notification::fake();
        $record = $this->record();
        $this->reject($record);
        $this->escalate();

        $this->actingAs(Admin::factory()->create(['role' => 'moderator']), 'admin')
            ->post(route('admin.returns.dispute', $record), [
                'outcome' => 'overturned',
                'note' => 'The damage photos match the buyer description.',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertSame(ParcelReturnStatus::Requested, $record->status);
        $this->assertSame(ParcelReturn::DISPUTE_OVERTURNED, $record->dispute_outcome);
        $this->assertTrue($record->isActive());
        $this->assertSame(ParcelReturn::REJECTED, $record->seller_decision);

        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))
            ->assertOk()
            ->assertSee('An admin approved this return')
            ->assertDontSee('You cannot change it');
        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.orders.show', $this->parcel->order))
            ->assertOk()
            ->assertSee('Return request approved')
            ->assertDontSee('Order Received');
        Notification::assertSentTo($this->seller, ReturnDisputeResolved::class);
    }

    private function pendingSettlement(): SellerSettlement
    {
        $payment = $this->parcel->payment;
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $courier = Courier::factory()->create(['logistics_provider_id' => $provider->id]);
        $remittance = new CodRemittance;
        $remittance->forceFill([
            'payment_id' => $payment->id,
            'seller_order_id' => $this->parcel->id,
            'courier_id' => $courier->id,
            'logistics_provider_id' => $provider->id,
            'amount_minor' => 10000,
            'status' => CodRemittance::REMITTED_TO_LOGISTICS,
            'confirmed_by_id' => $provider->id,
            'confirmed_at' => now(),
            'received_amount_minor' => 10000,
            'zefanya_received_at' => now(),
        ])->save();
        $settlement = new SellerSettlement;
        $settlement->forceFill([
            'seller_order_id' => $this->parcel->id,
            'cod_remittance_id' => $remittance->id,
            'payment_id' => $payment->id,
            'seller_id' => $this->seller->id,
            'collected_amount_minor' => 10000,
            'shipping_allocation_minor' => 0,
            'status' => SellerSettlement::PENDING_PAYOUT,
            'pending_reason' => SellerSettlement::COMMISSION_FINALIZATION_MISSING,
        ])->save();
        $this->parcel->forceFill([
            'subtotal_minor' => 10000,
            'shipping_fee_minor' => 0,
            'commission_bps' => 1000,
            'commission_minor' => 1000,
            'seller_net_minor' => 9000,
            'commission_finalized_at' => now(),
        ])->save();

        return $settlement;
    }

    public function test_sel_ret_007_approved_or_refunded_amounts_leave_pending_earnings_and_commission(): void
    {
        $settlement = $this->pendingSettlement();
        $record = $this->record();

        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))
            ->assertRedirect()->assertSessionHasNoErrors();

        $settlement->refresh();
        $this->parcel->refresh();
        $this->assertSame(SellerSettlement::RELEASED, $settlement->status);
        $this->assertSame(0, $settlement->commission_minor);
        $this->assertSame(0, $settlement->seller_payout_minor);
        $this->assertNull($settlement->pending_reason);
        // The rate stays on the order; only the amount drops to nothing.
        $this->assertSame(1000, $this->parcel->commission_bps);
        $this->assertSame(0, $this->parcel->commission_minor);
        $this->assertSame(0, $this->parcel->seller_net_minor);

        $this->parcel->forceFill(['status' => OrderStatus::Completed])->save();
        app(CommissionFinalizationService::class)->finalize($this->parcel->fresh());
        $this->assertSame(0, $this->parcel->fresh()->commission_minor);
        $this->assertSame(0, $this->parcel->fresh()->seller_net_minor);
        $this->assertSame(SellerSettlement::RELEASED, $settlement->fresh()->status);
    }

    public function test_sel_ret_007_an_open_or_rejected_return_keeps_pending_earnings(): void
    {
        $settlement = $this->pendingSettlement();
        $record = $this->record();

        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertSame(1000, $this->parcel->fresh()->commission_minor);
        $this->assertSame(9000, $this->parcel->fresh()->seller_net_minor);

        $this->reject($record);
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertSame(9000, $settlement->fresh()->seller_payout_minor);
        $this->assertSame(1000, $this->parcel->fresh()->commission_minor);
    }

    public function test_invalid_refund_preserves_the_submitted_form_context_and_values(): void
    {
        $this->pendingSettlement();
        $record = $this->record();
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $action = route('admin.returns.refund', $record);
        $values = ['_ui_form' => $action, 'reference' => 'REF-KEEP', 'amount' => 'bad-amount', 'recipient' => 'Juana', 'paid_on' => '2020-01-02', 'refund_reason' => 'Keep this explanation'];

        $this->actingAs($admin, 'admin')->from(route('admin.returns.index'))->followingRedirects()->post($action, $values)
            ->assertOk()->assertSee('name="_ui_form"', false)->assertSee('REF-KEEP')->assertSee('bad-amount')->assertSee('Keep this explanation');
        $this->assertNull($record->fresh()->refunded_at);
    }

    public function test_sel_ret_007_a_recorded_refund_releases_pending_earnings_once(): void
    {
        $settlement = $this->pendingSettlement();
        $record = $this->approvedRecord();
        $admin = Admin::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin, 'admin')->get(route('admin.returns.index'))
            ->assertOk()
            ->assertSee(route('admin.returns.refund', $record), false);

        $refund = ['reference' => 'COD-REF-1001', 'amount' => '50.25', 'recipient' => 'Juana Cruz, GCash 0917 123 4567', 'paid_on' => now('Asia/Manila')->toDateString(), 'refund_reason' => 'Only one of the two items was faulty.'];
        $this->post(route('admin.returns.refund', $record), $refund)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('COD-REF-1001', $record->fresh()->refund_reference);
        $this->assertSame(5025, $record->fresh()->refund_amount_minor);
        $this->assertSame('Juana Cruz, GCash 0917 123 4567', $record->fresh()->refund_recipient);
        $this->assertSame(now('Asia/Manila')->toDateString(), $record->fresh()->refund_paid_on->toDateString());
        $this->assertNull($record->fresh()->refund_proof_path);
        $this->assertNotNull($record->fresh()->refunded_at);
        // ₱50.25 of ₱100.00 refunded: the Seller keeps ₱49.75 of items, less 10% commission (₱4.98).
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertNull($settlement->fresh()->pending_reason);
        $this->assertSame(498, $settlement->fresh()->commission_minor);
        $this->assertSame(4477, $settlement->fresh()->seller_payout_minor);
        $this->assertSame(498, $this->parcel->fresh()->commission_minor);
        $this->assertSame(4477, $this->parcel->fresh()->seller_net_minor);

        $this->post(route('admin.returns.refund', $record), ['reference' => 'COD-REF-1002'] + $refund)
            ->assertSessionHasErrors('refund');
        $this->assertSame('COD-REF-1001', $record->fresh()->refund_reference);
        $this->assertSame(4477, $settlement->fresh()->seller_payout_minor);
    }

    public function test_refund_retries_by_two_admins_and_a_stale_model_do_not_repeat_financial_effects(): void
    {
        Notification::fake();
        $settlement = $this->pendingSettlement();
        $settlement->forceFill(['status' => SellerSettlement::PAID, 'paid_amount_minor' => 9000, 'seller_payout_minor' => 9000])->save();
        $record = $this->approvedRecord();
        // Exclude the return-request notification; these assertions concern refund attempts.
        Notification::fake();
        $staleRecord = $record->fresh();
        $firstAdmin = Admin::factory()->create(['role' => 'super_admin']);
        $secondAdmin = Admin::factory()->create(['role' => 'super_admin']);
        $payload = ['reference' => 'REF-RETRY', 'amount' => '20.00', 'recipient' => 'Juana Cruz',
            'paid_on' => now()->toDateString(), 'refund_reason' => 'Only one item in the shipment was damaged.'];
        $url = route('admin.returns.refund', $record);

        $this->actingAs($firstAdmin, 'admin')->post($url, $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1800, SellerDebt::sole()->amount_minor);
        $this->assertSame(1, $record->events()->where('event_key', 'like', 'refund_recorded%')->count());
        $saved = $this->refundFinancialSnapshot();

        // A lost response, then another Admin's already-open form, both retry the obligation.
        $this->postJson($url, $payload)->assertConflict()->assertJsonValidationErrors('refund');
        $this->actingAs($secondAdmin, 'admin')->postJson($url, ['reference' => 'REF-OTHER-ADMIN'] + $payload)
            ->assertConflict()->assertJsonValidationErrors('refund');

        // The service must re-read under lock, even if a caller cached an unrefunded model.
        $this->assertNull($staleRecord->refunded_at);
        try {
            app(ParcelReturnService::class)->recordRefund($staleRecord, $secondAdmin, 'REF-STALE', 2000,
                'Juana Cruz', now()->toDateString(), reason: $payload['refund_reason']);
            $this->fail('A stale return model must not allow a second refund.');
        } catch (ValidationException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertArrayHasKey('refund', $exception->errors());
        }

        $this->assertEquals($saved, $this->refundFinancialSnapshot());
        Notification::assertNothingSent();
    }

    public function test_failed_refund_rolls_back_events_debts_and_charges_and_can_be_retried_once(): void
    {
        Notification::fake();
        $settlement = $this->pendingSettlement();
        $settlement->forceFill(['status' => SellerSettlement::PAID, 'paid_amount_minor' => 9000, 'seller_payout_minor' => 9000])->save();
        $record = $this->record();
        Notification::fake();
        $record->forceFill(['status' => ParcelReturnStatus::Completed, 'seller_decision' => ParcelReturn::APPROVED,
            'origin_provider_id' => CodRemittance::sole()->logistics_provider_id, 'return_shipping_fee_minor' => 500])->save();
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $before = $this->refundFinancialSnapshot();
        $failingCharges = new class extends ReturnChargeService
        {
            public function sync(ParcelReturn $record): void
            {
                parent::sync($record);
                throw new \RuntimeException('Simulated failure after refund financial writes.');
            }
        };
        $this->app->instance(ReturnChargeService::class, $failingCharges);

        try {
            app(ParcelReturnService::class)->recordRefund($record, $admin, 'REF-ROLLBACK', 10000, 'Juana Cruz', now()->toDateString());
            $this->fail('The simulated downstream failure must escape the transaction.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated failure after refund financial writes.', $exception->getMessage());
        } finally {
            $this->app->forgetInstance(ReturnChargeService::class);
        }

        $this->assertEquals($before, $this->refundFinancialSnapshot());
        $this->assertNull($record->fresh()->refunded_at);

        app(ParcelReturnService::class)->recordRefund($record, $admin, 'REF-ROLLBACK', 10000, 'Juana Cruz', now()->toDateString());
        $this->assertSame('REF-ROLLBACK', $record->fresh()->refund_reference);
        $this->assertSame(9500, SellerDebt::sole()->amount_minor);
        $this->assertSame(500, LogisticsReturnCharge::sole()->amount_minor);
        $this->assertSame(1, $record->events()->where('event_key', 'like', 'refund_recorded%')->count());
        Notification::assertNothingSent();
    }

    private function refundFinancialSnapshot(): array
    {
        $snapshot = [];
        foreach (['parcel_returns', 'parcel_return_events', 'seller_orders', 'seller_settlements', 'seller_debts',
            'logistics_return_charges', 'operations_audit_logs', 'notifications'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        }

        return $snapshot;
    }

    public function test_a_failed_delivery_with_uncollected_cod_cannot_be_refunded(): void
    {
        $this->parcel->payment->forceFill(['status' => PaymentStatus::Pending, 'paid_at' => null])->save();
        $this->parcel->forceFill(['status' => OrderStatus::DeliveryFailed, 'delivery_attempts' => 2])->save();
        app(ParcelReturnService::class)->ensureAfterSecondFailure($this->parcel, null, null);
        $record = ParcelReturn::query()->sole();
        $before = $this->refundFinancialSnapshot();

        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin')
            ->postJson(route('admin.returns.refund', $record), [
                'reference' => 'NO-COD', 'amount' => '100.00', 'recipient' => 'Juana Cruz', 'paid_on' => today()->toDateString(),
            ])->assertUnprocessable()->assertJsonValidationErrors('refund');
        $this->assertEquals($before, $this->refundFinancialSnapshot());
    }

    public function test_refund_amount_is_capped_by_the_recorded_cash_collection(): void
    {
        $this->parcel->payment->forceFill(['collected_amount_minor' => 8000, 'collected_at' => now()])->save();
        $record = $this->approvedRecord();
        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin')
            ->postJson(route('admin.returns.refund', $record), [
                'reference' => 'ABOVE-COLLECTION', 'amount' => '100.00', 'recipient' => 'Juana Cruz', 'paid_on' => today()->toDateString(),
            ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertNull($record->fresh()->refunded_at);
    }

    public function test_a_debt_insert_failure_rolls_back_the_refund_and_its_history(): void
    {
        $settlement = $this->pendingSettlement();
        $settlement->forceFill(['status' => SellerSettlement::PAID, 'seller_payout_minor' => 9000, 'paid_amount_minor' => 9000])->save();
        $record = $this->record();
        $before = $this->refundFinancialSnapshot();
        SellerDebt::creating(fn () => throw new \RuntimeException('Simulated Seller debt write failure.'));

        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin')
            ->postJson(route('admin.returns.refund', $record), [
                'reference' => 'DEBT-ROLLBACK', 'amount' => '100.00', 'recipient' => 'Juana Cruz', 'paid_on' => today()->toDateString(),
            ] + self::REFUND_EXCEPTION + self::SELLER_FUNDS)->assertServerError();
        $this->assertEquals($before, $this->refundFinancialSnapshot());
    }

    public function test_stale_record_and_void_submissions_cannot_change_a_corrected_refund_or_debt(): void
    {
        $settlement = $this->pendingSettlement();
        $settlement->forceFill(['status' => SellerSettlement::PAID, 'seller_payout_minor' => 9000, 'paid_amount_minor' => 9000])->save();
        $record = $this->approvedRecord();
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $recordUrl = route('admin.returns.refund', $record);
        $voidUrl = route('admin.returns.refund.void', $record);
        $original = ['reference' => 'ORIGINAL', 'amount' => '20.00', 'recipient' => 'Juana Cruz', 'paid_on' => today()->toDateString(),
            'refund_reason' => 'Only part of the order was faulty.', 'refund_revision' => 0];

        $this->actingAs($admin, 'admin')->postJson($recordUrl, $original)->assertRedirect();
        $revision = $record->events()->where('event_key', 'refund_recorded')->sole()->id;
        $void = ['void_reason' => 'The first transfer bounced.', 'refund_revision' => $revision];
        $this->postJson($voidUrl, $void)->assertRedirect();
        $this->postJson($recordUrl, $original)->assertConflict()->assertJsonValidationErrors('refund');

        $this->postJson($recordUrl, ['reference' => 'REPLACEMENT', 'amount' => '30.00', 'refund_revision' => $record->refundHistory()->max('id')] + $original)
            ->assertRedirect();
        $before = $this->refundFinancialSnapshot();
        $this->postJson($voidUrl, $void)->assertConflict()->assertJsonValidationErrors('void_reason');
        $this->assertEquals($before, $this->refundFinancialSnapshot());
        $this->assertSame('REPLACEMENT', $record->fresh()->refund_reference);
        $this->assertSame(2700, SellerDebt::query()->sole()->outstandingMinor());
    }

    public function test_preparing_a_partly_paid_refunded_settlement_preserves_its_reconciliation_hold(): void
    {
        $settlement = $this->pendingSettlement();
        $this->parcel->payment->forceFill(['collected_at' => now(), 'collected_amount_minor' => 10000])->save();
        $settlement->forceFill(['seller_payout_minor' => 9000, 'paid_amount_minor' => 4000, 'pending_reason' => null])->save();
        $record = $this->approvedRecord();
        $this->refund($record, '80.00');
        $this->assertSame(2200, SellerDebt::query()->sole()->outstandingMinor());
        $before = $settlement->fresh()->getAttributes();

        app(SellerSettlementService::class)->prepare(CodRemittance::query()->sole());

        $this->assertSame($before, $settlement->fresh()->getAttributes());
        $this->assertSame(SellerSettlement::FINANCIAL_RECONCILIATION_REQUIRED, $settlement->fresh()->pending_reason);
        $this->assertSame(2200, SellerDebt::query()->sole()->outstandingMinor());
    }

    public static function malformedRefundDestinationFields(): array
    {
        return [
            ['recipient', ''], ['recipient', '   '], ['recipient', ['Another account']], ['recipient', str_repeat('a', 256)],
            ['reference', ''], ['reference', '   '], ['reference', ['REF-001']], ['reference', str_repeat('a', 256)],
        ];
    }

    #[DataProvider('malformedRefundDestinationFields')]
    public function test_malformed_destination_fields_do_not_change_refund_financial_records(string $field, mixed $value): void
    {
        $record = $this->record();
        $before = $this->refundFinancialSnapshot();
        $data = ['reference' => 'REF-001', 'recipient' => 'Buyer A - test account', 'amount' => '100.00', 'paid_on' => today()->toDateString()];
        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin')
            ->postJson(route('admin.returns.refund', $record), array_replace($data, [$field => $value]))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertEquals($before, $this->refundFinancialSnapshot());
    }

    public function test_forged_buyer_order_and_return_parameters_do_not_reassociate_a_refund(): void
    {
        $record = $this->approvedRecord();
        $otherBuyer = Buyer::factory()->create();
        $other = SellerOrder::factory()->create(['order_id' => Order::factory()->create(['buyer_id' => $otherBuyer->id])->id]);
        $otherPayment = Payment::factory()->paid()->create(['seller_order_id' => $other->id]);
        $otherReturn = ParcelReturn::query()->forceCreate([
            'seller_order_id' => $other->id, 'reason' => 'Wrong item received', 'requested_at' => now(), 'financial_review_required' => true,
        ]);
        $before = [$other->fresh()->getAttributes(), $otherPayment->fresh()->getAttributes(), $otherReturn->fresh()->getAttributes()];
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'admin')->postJson(route('admin.returns.refund', $record), [
            'reference' => 'REF-ORDER-A', 'recipient' => 'Buyer A - test account', 'amount' => '100.00', 'paid_on' => today()->toDateString(),
            'buyer_id' => $otherBuyer->id, 'order_id' => $other->order_id, 'seller_order_id' => $other->id,
            'return_id' => $otherReturn->id, 'parcel_return_id' => $otherReturn->id,
            'refund_recipient' => 'FORGED account', 'refund_reference' => 'FORGED reference',
            'refund_amount_minor' => 999999, 'status' => ParcelReturnStatus::Completed->value,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($this->parcel->id, $record->fresh()->seller_order_id);
        $this->assertSame($this->buyer->id, $record->fresh()->sellerOrder->order->buyer_id);
        $this->assertSame('Buyer A - test account', $record->fresh()->refund_recipient);
        $this->assertSame('REF-ORDER-A', $record->fresh()->refund_reference);
        $this->assertSame(10000, $record->fresh()->refund_amount_minor);
        $this->assertSame(ParcelReturnStatus::Requested, $record->fresh()->status);
        $event = $record->refundHistory()->sole();
        $this->assertSame($admin->id, (int) $event->actor_id);
        $this->assertSame('Buyer A - test account', $event->metadata['recipient']);
        $this->assertEquals($before, [$other->fresh()->getAttributes(), $otherPayment->fresh()->getAttributes(), $otherReturn->fresh()->getAttributes()]);
    }

    public function test_recipient_and_reference_are_manual_attestations_without_bank_verification_or_required_proof(): void
    {
        $record = $this->approvedRecord();
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'admin')->postJson(route('admin.returns.refund', $record), [
            'reference' => 'FABRICATED-REFERENCE-FOR-AUDIT', 'recipient' => 'Unrelated person - test account',
            'amount' => '100.00', 'paid_on' => today()->toDateString(),
        ])->assertRedirect()->assertSessionHasNoErrors();
        // Pin the existing limitation, without inventing a verified-beneficiary policy.
        $this->assertSame('Unrelated person - test account', $record->fresh()->refund_recipient);
        $this->assertNull($record->fresh()->refund_proof_path);
        $event = $record->refundHistory()->sole();
        $this->assertSame('admin', $event->actor_type);
        $this->assertSame($admin->id, (int) $event->actor_id);
        $this->assertSame('FABRICATED-REFERENCE-FOR-AUDIT', $event->metadata['reference']);
        $this->assertFalse($event->metadata['has_proof']);
    }

    public function test_a_refund_reference_cannot_be_reused_on_another_order_until_its_refund_is_voided(): void
    {
        $record = $this->approvedRecord();
        $other = SellerOrder::factory()->create();
        Payment::factory()->paid()->create(['seller_order_id' => $other->id]);
        $otherReturn = ParcelReturn::query()->forceCreate([
            'seller_order_id' => $other->id, 'reason' => 'Wrong item received', 'requested_at' => now(), 'financial_review_required' => true,
        ]);
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $service = app(ParcelReturnService::class);
        $service->recordRefund($record, $admin, 'REF-001', 10000, 'Test recipient', today()->toDateString());

        try {
            $service->recordRefund($otherReturn, $admin, 'ref-001', 10000, 'Other recipient', today()->toDateString());
            $this->fail('A second order reused a live refund reference.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reference', $e->errors());
        }
        $this->assertNull($otherReturn->fresh()->refunded_at);
        $this->assertSame(0, $otherReturn->refundHistory()->count());

        // A voided refund keeps its reference protected: another order still cannot use it.
        $service->voidRefund($record->fresh(), $admin, 'The bank returned this transfer.', (int) $record->refundHistory()->max('id'));
        try {
            $service->recordRefund($otherReturn, $admin, 'REF-001', 10000, 'Other recipient', today()->toDateString());
            $this->fail('A voided refund reference was reused on another order.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('reversed refund', $e->errors()['reference'][0]);
        }
        $this->assertNull($otherReturn->fresh()->refunded_at);

        // Only the same return may re-record that transfer, for the same amount.
        try {
            $service->recordRefund($record->fresh(), $admin, 'REF-001', 9000, 'Test recipient', today()->toDateString(), refundRevision: (int) $record->refundHistory()->max('id'),
                reason: 'Only part of the order was faulty.');
            $this->fail('A voided refund reference was reused for a different amount.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reference', $e->errors());
        }
        $service->recordRefund($record->fresh(), $admin, 'REF-001', 10000, 'Test recipient', today()->toDateString(), refundRevision: (int) $record->refundHistory()->max('id'));
        $this->assertSame('REF-001', $record->fresh()->refund_reference);
    }

    public function test_destination_correction_preserves_original_evidence_and_one_order_debt(): void
    {
        $settlement = $this->pendingSettlement();
        $settlement->forceFill(['status' => SellerSettlement::PAID, 'seller_payout_minor' => 9000, 'paid_amount_minor' => 9000])->save();
        $record = $this->approvedRecord();
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $data = ['reference' => 'REF-SAME-TRANSFER', 'recipient' => 'Wrong account - test only', 'amount' => '100.00',
            'paid_on' => today()->toDateString(), 'proof' => UploadedFile::fake()->image('old-evidence.png')];
        $this->actingAs($admin, 'admin')->postJson(route('admin.returns.refund', $record), $data)->assertRedirect();
        $original = $record->refundHistory()->sole();
        $proof = $record->fresh()->refund_proof_path;
        $originalMetadata = $original->metadata;
        $paymentBefore = $this->parcel->payment->fresh()->getAttributes();
        $this->postJson(route('admin.returns.refund.void', $record), [
            'void_reason' => 'Transfer to the incorrect account bounced.', 'refund_revision' => $original->id,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $voided = $record->refundHistory()->reorder('id', 'desc')->firstOrFail();
        $this->assertSame('Wrong account - test only', $voided->metadata['recipient']);
        $this->assertSame($proof, $voided->metadata['proof_path']);
        $this->assertSame('REF-SAME-TRANSFER', $voided->metadata['reference']);
        $this->assertSame(10000, $voided->metadata['amount_minor']);
        $this->assertSame($data['paid_on'], $voided->metadata['paid_on']);
        Storage::disk('local')->assertExists($proof);
        $replacement = ['recipient' => 'Correct account - test only', 'refund_revision' => $voided->id] + $data;
        unset($replacement['proof']);
        // The voided reference cannot be sent to another account without verified payment evidence.
        $this->postJson(route('admin.returns.refund', $record), $replacement)->assertUnprocessable()->assertJsonValidationErrors('reference');
        $this->assertNull($record->fresh()->refunded_at);
        $replacement['reference'] = 'REF-CORRECT-TRANSFER';
        $this->postJson(route('admin.returns.refund', $record), $replacement)->assertRedirect()->assertSessionHasNoErrors();
        $beforeReplay = $this->refundFinancialSnapshot();
        $this->postJson(route('admin.returns.refund', $record), $data)->assertConflict()->assertJsonValidationErrors('refund');
        $this->assertEquals($beforeReplay, $this->refundFinancialSnapshot());
        $this->assertEquals($originalMetadata, $original->fresh()->metadata);
        $this->assertSame('Correct account - test only', $record->fresh()->refund_recipient);
        $this->assertSame(3, $record->refundHistory()->count());
        $this->assertSame(9000, SellerDebt::sole()->amount_minor);
        $this->assertSame(0, SellerDebt::sole()->settled_minor);
        $this->assertSame($settlement->id, SellerDebt::sole()->seller_settlement_id);
        $this->assertSame(9000, $settlement->fresh()->paid_amount_minor);
        $this->assertSame($paymentBefore, $this->parcel->payment->fresh()->getAttributes());
    }

    public function test_refund_destination_and_proof_are_not_exposed_on_seller_buyer_or_logistics_surfaces(): void
    {
        $settlement = $this->pendingSettlement();
        $record = $this->approvedRecord();
        $provider = $settlement->codRemittance->provider;
        $record->forceFill(['origin_provider_id' => $provider->id])->save();
        $recipient = 'PRIVATE-DESTINATION-TEST-000123456789';
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'admin')->postJson(route('admin.returns.refund', $record), [
            'reference' => 'PRIVATE-REF-TEST', 'recipient' => $recipient, 'amount' => '100.00',
            'paid_on' => today()->toDateString(), 'proof' => UploadedFile::fake()->image('private-receipt.png'),
        ])->assertRedirect();
        $proof = $record->fresh()->refund_proof_path;
        $this->get(route('admin.returns.index'))->assertOk()->assertSee($recipient);
        $this->get(route('admin.returns.refund-proof', $record))->assertOk();
        auth('admin')->logout();

        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))
            ->assertOk()->assertDontSee($recipient)->assertDontSee($proof)->assertDontSee('PRIVATE-REF-TEST');
        $this->getJson(route('admin.returns.refund-proof', $record))->assertUnauthorized();
        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.orders.show', $this->parcel->order))
            ->assertOk()->assertDontSee($recipient)->assertDontSee($proof);
        $this->actingAs($provider, 'logistics')->get(route('logistics.returns.show', $record))
            ->assertOk()->assertDontSee($recipient)->assertDontSee($proof)->assertDontSee('PRIVATE-REF-TEST');
        $this->get(route('logistics.audit-logs.index'))->assertOk()->assertDontSee($recipient)->assertDontSee($proof);

        $parcel = $this->parcel->fresh()->load('parcelReturn.events');
        $request = Request::create('/');
        foreach ([new ShipmentResource($parcel), new RiderParcelResource($parcel)] as $resource) {
            $json = json_encode($resource->resolve($request), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString($recipient, $json);
            $this->assertStringNotContainsString($proof, $json);
        }
        $this->actingAs(Buyer::factory()->create(), 'buyer')->get(route('buyer.orders.show', $this->parcel->order))->assertNotFound();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $token = $this->buyer->createToken('refund-destination-audit', ['buyer'], now()->addHour())->plainTextToken;
        $this->withToken($token)->getJson(route('api.buyer.orders.show', $this->parcel->order))
            ->assertOk()->assertDontSee($recipient)->assertDontSee($proof);
    }

    public static function manipulatedRefundAmounts(): array
    {
        return [['0.00'], ['-1.00'], ['100.01'], ['1e2'], ['0.001'], [['100.00']], ['9999999999']];
    }

    #[DataProvider('manipulatedRefundAmounts')]
    public function test_manipulated_refund_amounts_cannot_change_financial_records(mixed $amount): void
    {
        $record = $this->approvedRecord();
        $before = $this->refundFinancialSnapshot();
        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin')
            ->postJson(route('admin.returns.refund', $record), [
                'reference' => 'MANIPULATED', 'amount' => $amount, 'amount_minor' => 1,
                'recipient' => 'Buyer', 'paid_on' => today()->toDateString(),
                'refund_reason' => 'Only some items were damaged.', 'refunded_at' => now()->toDateTimeString(),
            ])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->assertEquals($before, $this->refundFinancialSnapshot());
    }

    public static function unauthorizedRefundActors(): array
    {
        return [
            ['buyer', Buyer::class, []], ['seller', Seller::class, []],
            ['logistics', LogisticsProvider::class, []], ['rider', Rider::class, []],
            ['admin', Admin::class, ['role' => 'support']], ['admin', Admin::class, ['role' => 'moderator']],
        ];
    }

    #[DataProvider('unauthorizedRefundActors')]
    public function test_direct_refund_and_void_requests_require_a_money_admin(string $guard, string $model, array $attributes): void
    {
        $record = $this->record();
        auth('buyer')->logout();
        $this->actingAs($model::factory()->create($attributes), $guard);
        $before = $this->refundFinancialSnapshot();
        $expected = $guard === 'admin' ? 403 : 401;
        $this->postJson(route('admin.returns.refund', $record), [
            'reference' => 'UNAUTHORIZED', 'amount' => '100.00', 'recipient' => 'Buyer', 'paid_on' => today()->toDateString(),
        ])->assertStatus($expected);
        $this->postJson(route('admin.returns.refund.void', $record), [
            'void_reason' => 'An unrelated actor tried to void it.', 'refund_revision' => 0,
        ])->assertStatus($expected);
        $this->assertEquals($before, $this->refundFinancialSnapshot());
    }

    public function test_cancelled_return_is_not_a_platform_refund_liability(): void
    {
        $record = $this->record();
        app(ParcelReturnService::class)->approveBySeller($record, $this->seller);
        $this->assertSame(10000, app(PlatformFinanceService::class)->position()['refundsToRecordMinor']);
        app(ParcelReturnService::class)->cancelByBuyer($record, $this->buyer, 'Found the missing part.');

        $position = app(PlatformFinanceService::class)->position();
        $this->assertTrue($record->fresh()->isCancelled());
        $this->assertSame(0, $position['refundsToRecordCount']);
        $this->assertSame(0, $position['refundsToRecordMinor']);
        $this->assertSame(0, $position['totalLiabilitiesMinor']);
        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin')->get(route('admin.dashboard'))
            ->assertOk()->assertViewHas('attention', fn (array $rows) => collect($rows)->firstWhere('label', 'Refunds to record')['count'] === 0);
    }

    public function test_platform_refund_liability_is_capped_by_collected_cash(): void
    {
        $this->parcel->payment->forceFill(['collected_amount_minor' => 6000, 'collected_at' => now()])->save();
        $record = $this->record();
        app(ParcelReturnService::class)->approveBySeller($record, $this->seller);

        $position = app(PlatformFinanceService::class)->position();
        $this->assertSame(1, $position['refundsToRecordCount']);
        $this->assertSame(6000, $position['refundsToRecordMinor']);
        $this->assertSame(6000, $position['totalLiabilitiesMinor']);
    }

    public function test_uncollected_cod_does_not_become_a_platform_refund_liability(): void
    {
        $this->parcel->payment->forceFill(['status' => PaymentStatus::Pending, 'paid_at' => null])->save();
        $record = $this->record();
        app(ParcelReturnService::class)->approveBySeller($record, $this->seller);

        $position = app(PlatformFinanceService::class)->position();
        $this->assertSame(0, $position['refundsToRecordCount']);
        $this->assertSame(0, $position['refundsToRecordMinor']);
        $this->assertNull($record->fresh()->refunded_at);
    }

    public function test_unpaid_refund_survives_time_and_suspension_and_void_reopens_its_balance(): void
    {
        $record = $this->record();
        $returns = app(ParcelReturnService::class);
        $returns->approveBySeller($record, $this->seller);
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $this->travel(40)->days();
        $this->actingAs($admin, 'admin')->post(route('admin.users.seller.suspend', $this->seller), ['reason' => 'Account reviewed by support.'])
            ->assertSessionHasNoErrors();
        $this->assertSame('suspended', $this->seller->fresh()->status);
        $this->assertSame(10000, app(PlatformFinanceService::class)->position()['refundsToRecordMinor']);
        $this->get(route('admin.returns.index'))->assertOk()->assertSee(route('admin.returns.refund', $record), false);
        $returns->recordRefund($record, $admin, 'MANUAL-TRANSFER', 10000, 'Juana Cruz', today()->toDateString());
        $this->assertSame(0, app(PlatformFinanceService::class)->position()['refundsToRecordMinor']);
        $returns->voidRefund($record, $admin, 'The transfer bounced back.', (int) $record->refundHistory()->max('id'));
        $this->assertSame(10000, app(PlatformFinanceService::class)->position()['refundsToRecordMinor']);
        $this->assertSame(2, $record->refundHistory()->count());
    }

    /** A refund on a return that was not approved needs a documented exception (Audit 1.13 refund policy). */
    private const REFUND_EXCEPTION = ['refund_exception_basis' => 'separate_approval', 'refund_exception_note' => 'Approved by the finance lead in ticket FIN-42.'];

    /** An exception refund with no earlier fault decision needs an explicit, justified payer. */
    private const SELLER_FUNDS = ['refund_funding_party' => 'seller', 'refund_funding_note' => 'The Seller agreed in ticket FIN-42 to fund this refund.'];

    private function refund(ParcelReturn $record, string $amount, bool $exception = false, bool $sellerFunds = false): void
    {
        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin')
            ->post(route('admin.returns.refund', $record), ($exception ? self::REFUND_EXCEPTION : []) + ($sellerFunds ? self::SELLER_FUNDS : []) + ['reference' => 'COD-REF-'.$record->id, 'amount' => $amount, 'recipient' => 'Juana Cruz', 'paid_on' => now('Asia/Manila')->toDateString(), 'refund_reason' => 'Only part of the order was faulty.', 'refund_revision' => (int) $record->refundHistory()->max('id')])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_a_full_refund_leaves_the_seller_nothing(): void
    {
        $settlement = $this->pendingSettlement();
        $this->refund($this->approvedRecord(), '100.00');

        $this->assertSame(SellerSettlement::RELEASED, $settlement->fresh()->status);
        $this->assertSame(0, $settlement->fresh()->seller_payout_minor);
        $this->assertSame(0, $this->parcel->fresh()->seller_net_minor);
    }

    public function test_a_refund_reference_used_by_another_refund_is_refused_and_nothing_is_recorded(): void
    {
        $this->pendingSettlement();
        $record = $this->approvedRecord();
        // Another return's refund already used this bank reference.
        $other = (new ParcelReturn)->forceFill(['id' => $record->id + 1000]);
        app(FinancialReferenceRegistry::class)->claim(FinancialReference::REFUND, 'COD-REF-'.$record->id, $other);

        $this->actingAs(Admin::factory()->superAdmin()->create(), 'admin')
            ->post(route('admin.returns.refund', $record), ['reference' => 'cod-ref-'.$record->id, 'amount' => '100.00', 'recipient' => 'Juana Cruz',
                'paid_on' => now('Asia/Manila')->toDateString(), 'refund_revision' => 0])
            ->assertSessionHasErrors('reference');

        $this->assertNull($record->fresh()->refunded_at);
        $this->assertSame(0, $record->refundHistory()->count());
    }

    public function test_an_admin_without_super_admin_role_cannot_record_a_refund(): void
    {
        $this->pendingSettlement();
        $record = $this->approvedRecord();

        $this->actingAs(Admin::factory()->role(\App\Enums\AdminRole::Admin)->create(), 'admin')
            ->post(route('admin.returns.refund', $record), ['reference' => 'COD-ADMIN', 'amount' => '100.00', 'recipient' => 'Juana Cruz',
                'paid_on' => now('Asia/Manila')->toDateString(), 'refund_revision' => 0])
            ->assertForbidden();

        $this->assertNull($record->fresh()->refunded_at);
        $this->assertSame(0, $record->refundHistory()->count());
    }

    public function test_an_order_finalized_at_five_percent_keeps_that_rate_through_a_return(): void
    {
        $settlement = $this->pendingSettlement();
        $this->parcel->forceFill(['commission_bps' => 500, 'commission_minor' => 500, 'seller_net_minor' => 9500])->save();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->assertSame(500, $this->parcel->fresh()->commission_bps);
        $this->assertSame(0, $this->parcel->fresh()->commission_minor);

        $this->refund($record, '20.00');

        // ₱80.00 of items kept, less 5% (₱4.00), not today's 10%.
        $this->assertSame(500, $this->parcel->fresh()->commission_bps);
        $this->assertSame(400, $this->parcel->fresh()->commission_minor);
        $this->assertSame(7600, $this->parcel->fresh()->seller_net_minor);
        $this->assertSame(400, $settlement->fresh()->commission_minor);
        $this->assertSame(7600, $settlement->fresh()->seller_payout_minor);

        $this->parcel->payment->forceFill(['collected_at' => now(), 'collected_amount_minor' => 10000])->save();
        $this->parcel->forceFill(['status' => OrderStatus::Completed])->save();
        app(CommissionFinalizationService::class)->finalize($this->parcel->fresh());
        $this->assertSame(7600, $settlement->fresh()->seller_payout_minor);
        $this->assertSame(500, $settlement->fresh()->commission_bps);

        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $paid = app(SellerSettlementService::class)->confirmPayout($settlement->fresh(), $admin, 7600, 'KEPT-FIVE-PERCENT');
        $this->assertSame(SellerSettlement::PAID, $paid->status);
        $this->assertSame(7600, $paid->paid_amount_minor);
        $this->assertSame(400, $paid->commission_minor);
        $this->assertSame(500, $paid->commission_bps);
    }

    public function test_cancelling_an_uncollected_return_restores_completed_earnings_at_the_saved_rate(): void
    {
        $settlement = $this->pendingSettlement();
        $this->parcel->forceFill(['commission_bps' => 500, 'commission_minor' => 500, 'seller_net_minor' => 9500,
            'commission_finalized_at' => now()->subDay()])->save();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->assertSame(500, $this->parcel->fresh()->commission_bps);

        $this->actingAs($this->buyer, 'buyer')->post(route('buyer.orders.cancelReturn', [$this->parcel->order_id, $this->parcel]))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(now()->subDay(), $this->parcel->fresh()->commission_finalized_at);
        $this->assertSame(500, $this->parcel->fresh()->commission_bps);
        $this->assertSame(500, $this->parcel->fresh()->commission_minor);
        $this->assertSame(9500, $this->parcel->fresh()->seller_net_minor);
        $this->assertSame(500, $settlement->fresh()->commission_bps);
        $this->assertSame(9500, $settlement->fresh()->seller_payout_minor);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_refund_date_uses_the_current_manila_calendar_day_at_the_utc_boundary(bool $tomorrow): void
    {
        $record = $this->approvedRecord();
        Carbon::setTestNow(Carbon::parse('2026-11-01 16:30:00', 'UTC'));
        $paidOn = now('Asia/Manila')->addDays($tomorrow ? 1 : 0)->toDateString();
        $response = $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin')
            ->postJson(route('admin.returns.refund', $record), [
                'reference' => 'MANILA-DATE', 'amount' => '100.00', 'recipient' => 'Buyer', 'paid_on' => $paidOn,
            ]);
        if ($tomorrow) {
            $response->assertUnprocessable()->assertJsonValidationErrors('paid_on');
            $this->assertNull($record->fresh()->refunded_at);
        } else {
            $response->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($paidOn, $record->fresh()->refund_paid_on->toDateString());
            $this->assertSame(10000, $record->fresh()->refund_amount_minor);
        }
    }

    public function test_partial_refund_updates_the_recognized_commission(): void
    {
        $this->pendingSettlement();
        $this->refund($this->approvedRecord(), '20.00');
        $this->assertSame(OrderStatus::Completed, $this->parcel->fresh()->status);
        $this->assertNotNull($this->parcel->fresh()->commission_finalized_at);
        $this->assertSame(800, $this->parcel->fresh()->commission_minor);
        $this->assertSame(800, app(PlatformFinanceService::class)->earnings(false)['commissionMinor']);
    }

    public function test_a_partial_refund_after_approval_gives_the_seller_back_the_unrefunded_part(): void
    {
        $settlement = $this->pendingSettlement();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->assertSame(SellerSettlement::RELEASED, $settlement->fresh()->status);

        $this->refund($record, '20.00');

        // ₱80.00 of items kept, less ₱8.00 commission.
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertSame(800, $settlement->fresh()->commission_minor);
        $this->assertSame(7200, $settlement->fresh()->seller_payout_minor);

        // Completing the order later keeps the same split.
        $this->parcel->forceFill(['status' => OrderStatus::Completed])->save();
        app(CommissionFinalizationService::class)->finalize($this->parcel->fresh());
        $this->assertSame(7200, $this->parcel->fresh()->seller_net_minor);
        $this->assertSame(7200, $settlement->fresh()->seller_payout_minor);
    }

    public function test_a_partial_refund_stays_payable_at_the_kept_seller_net(): void
    {
        $settlement = $this->pendingSettlement();
        $this->parcel->payment->forceFill(['collected_at' => now(), 'collected_amount_minor' => 10000])->save();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->refund($record, '20.00');
        $this->parcel->forceFill(['status' => OrderStatus::Completed])->save();
        app(CommissionFinalizationService::class)->finalize($this->parcel->fresh());

        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $settlements = app(SellerSettlementService::class);
        try {
            $settlements->confirmPayout($settlement->fresh(), $admin, 9000, 'ORIGINAL-NET');
            $this->fail('The pre-refund seller net must not be paid.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_minor', $exception->errors());
        }

        $this->assertSame(7200, app(SellerPayoutRequestService::class)->availableBalance($this->seller));
        $this->actingAs($this->seller, 'seller')->get(route('seller.earnings.index'))
            ->assertOk()
            ->assertSee('Returns and charges')
            ->assertViewHas('grossMinor', 10000)
            ->assertViewHas('commissionMinor', 800)
            ->assertViewHas('returnAdjustmentMinor', 2000)
            ->assertViewHas('netMinor', 7200);

        $paid = $settlements->confirmPayout($settlement->fresh(), $admin, 7200, 'KEPT-NET');
        $this->assertSame(SellerSettlement::PAID, $paid->status);
        $this->assertSame(7200, $paid->paid_amount_minor);
        $this->assertSame(800, $paid->commission_minor);
        $this->assertSame(10000, $paid->collected_amount_minor);
        $this->assertSame(0, app(SellerPayoutRequestService::class)->availableBalance($this->seller));
    }

    public function test_a_partial_refund_after_payout_charges_the_seller_only_what_was_refunded(): void
    {
        $settlement = $this->pendingSettlement();
        $settlement->forceFill(['status' => SellerSettlement::PAID, 'seller_payout_minor' => 9000, 'paid_amount_minor' => 9000, 'pending_reason' => null])->save();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->assertSame(9000, SellerDebt::sole()->amount_minor);

        $this->refund($record, '20.00');

        // Paid ₱90.00, keeps ₱72.00, so owes back ₱18.00 (₱20.00 refunded less the ₱2.00 commission on it).
        $this->assertSame(1800, SellerDebt::sole()->amount_minor);
        $this->assertSame(SellerSettlement::PAID, $settlement->fresh()->status);
    }

    private function setFault(ParcelReturn $record, string $party, string $note = 'Courier crushed the box in transit.'): TestResponse
    {
        return $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin')
            ->post(route('admin.returns.fault', $record), ['fault_party' => $party, 'fault_note' => $note]);
    }

    public function test_approval_makes_the_seller_pay_by_default_and_both_pages_say_so(): void
    {
        $this->pendingSettlement();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();

        $this->assertSame(ParcelReturn::FAULT_SELLER, $record->fresh()->fault_party);
        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))->assertOk()
            ->assertSee('Who pays for this return')
            ->assertSee('You: the item was wrong, defective or not as described.')
            ->assertSee('Buyer paid ₱100.00')
            ->assertSee('on hold until the refund is recorded');
        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin')->get(route('admin.returns.index'))->assertOk()
            ->assertSee('Seller (wrong, defective or not as described) · default after approval')
            ->assertSee('Save who pays')
            ->assertSee('A full refund is everything the buyer paid, shipping included.')
            ->assertDontSee('Shipping is not reimbursed here.');
    }

    public function test_a_logistics_fault_leaves_the_seller_paid_in_full_and_is_audited(): void
    {
        $settlement = $this->pendingSettlement();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->assertSame(SellerSettlement::RELEASED, $settlement->fresh()->status);

        $this->setFault($record, ParcelReturn::FAULT_LOGISTICS)->assertSessionHasNoErrors();
        $this->refund($record, '100.00');

        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertSame(9000, $settlement->fresh()->seller_payout_minor);
        $this->assertSame(1000, $settlement->fresh()->commission_minor);
        $this->assertDatabaseHas('operations_audit_logs', ['action' => 'return_fault_set']);
        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))
            ->assertSee('You are not charged for this return.')
            ->assertSee('You keep ₱90.00 from this order after ₱10.00 commission.');
        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin')->get(route('admin.returns.index'))
            ->assertSee('Logistics (damaged in transit) · set by an admin')
            ->assertSee('Courier crushed the box in transit.')
            ->assertDontSee('Save who pays');
    }

    public function test_a_logistics_fault_after_payout_cancels_the_sellers_debt(): void
    {
        $settlement = $this->pendingSettlement();
        $settlement->forceFill(['status' => SellerSettlement::PAID, 'seller_payout_minor' => 9000, 'paid_amount_minor' => 9000, 'pending_reason' => null])->save();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->assertSame(9000, SellerDebt::sole()->amount_minor);

        $this->setFault($record, ParcelReturn::FAULT_LOGISTICS)->assertSessionHasNoErrors();

        $this->assertSame(0, SellerDebt::sole()->outstandingMinor());
        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))->assertDontSee('taken back from your next payouts');
    }

    public function test_a_logistics_fault_does_not_create_a_seller_debt_when_legacy_funding_is_unknown(): void
    {
        $settlement = $this->pendingSettlement();
        $settlement->forceFill(['status' => SellerSettlement::PAID, 'seller_payout_minor' => 9000, 'paid_amount_minor' => 9000])->save();
        $this->parcel->order->forceFill(['discount_minor' => 1000])->save();
        $this->parcel->forceFill(['voucher_discount_minor' => 1000, 'voucher_funding_source' => null])->save();
        $record = $this->record();
        $this->setFault($record, ParcelReturn::FAULT_LOGISTICS)->assertSessionHasNoErrors();

        $this->refund($record, exception: true, amount: '100.00');

        $this->assertDatabaseCount('seller_debts', 0);
        $this->assertSame(9000, $settlement->fresh()->paid_amount_minor);
        $this->assertSame(10000, $record->returnCharges()->where('reason', LogisticsReturnCharge::TRANSIT_DAMAGE_REFUND)->sole()->amount_minor);
    }

    public function test_unknown_legacy_funding_does_not_turn_a_partial_refund_into_debt_for_the_whole_payout(): void
    {
        $settlement = $this->pendingSettlement();
        $settlement->forceFill(['status' => SellerSettlement::PAID, 'seller_payout_minor' => 9000, 'paid_amount_minor' => 9000])->save();
        $this->parcel->order->forceFill(['discount_minor' => 1000])->save();
        $this->parcel->forceFill(['voucher_discount_minor' => 1000, 'voucher_funding_source' => null])->save();
        $record = $this->record();

        $this->refund($record, exception: true, sellerFunds: true, amount: '20.00');

        $this->assertSame(2000, $record->fresh()->refund_amount_minor);
        $this->assertSame(9000, $settlement->fresh()->paid_amount_minor);
        $this->assertDatabaseCount('seller_debts', 0);
    }

    public function test_other_as_the_reason_blocks_the_refund_until_an_admin_decides_who_pays(): void
    {
        $this->pendingSettlement();
        $this->requestReturn(['reason' => 'Other'])->assertCreated();
        $record = ParcelReturn::sole();
        $admin = Admin::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin, 'admin')->get(route('admin.returns.index'))
            ->assertSee('The buyer chose "Other" as the reason.', false)
            ->assertSee('Recording the refund is available after you decide who pays.')
            ->assertDontSee(route('admin.returns.refund', $record), false);
        $this->post(route('admin.returns.refund', $record), ['reference' => 'R-1', 'amount' => '10.00', 'recipient' => 'Juana', 'paid_on' => now('Asia/Manila')->toDateString()])
            ->assertSessionHasErrors('refund');
        $this->assertNull($record->fresh()->refunded_at);

        $this->setFault($record, ParcelReturn::FAULT_SELLER, 'Photos show the wrong colour was sent.')->assertSessionHasNoErrors();
        $this->refund($record, exception: true, amount: '10.00');
        $this->assertNotNull($record->fresh()->refunded_at);
    }

    public function test_who_pays_needs_a_reason_and_is_locked_once_refunded(): void
    {
        $this->pendingSettlement();
        $record = $this->record();

        $this->setFault($record, ParcelReturn::FAULT_LOGISTICS, 'short')->assertSessionHasErrors('fault_note');
        $this->setFault($record, 'buyer')->assertSessionHasErrors('fault_party');
        $this->assertNull($record->fresh()->fault_party);

        $this->refund($record, exception: true, sellerFunds: true, amount: '100.00');
        $this->assertSame(ParcelReturn::FAULT_SELLER, $record->fresh()->fault_party);
        $this->setFault($record, ParcelReturn::FAULT_LOGISTICS)->assertSessionHasErrors('fault_party');
        $this->assertSame(ParcelReturn::FAULT_SELLER, $record->fresh()->fault_party);
    }

    public function test_the_seller_page_explains_a_partial_refund(): void
    {
        $this->pendingSettlement();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->refund($record, '20.00');

        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))->assertOk()
            ->assertSee('Buyer refunded ₱20.00 (partial refund).')
            ->assertSee('You keep ₱72.00 from this order after ₱8.00 commission.');
    }

    /** ₱100.00 of items plus ₱50.00 shipping; the buyer paid ₱150.00. */
    private function settlementWithShipping(): SellerSettlement
    {
        $settlement = $this->pendingSettlement();
        $this->parcel->forceFill(['shipping_fee_minor' => 5000])->save();
        $this->parcel->payment->forceFill(['amount_minor' => 15000])->save();
        $settlement->forceFill(['collected_amount_minor' => 15000, 'shipping_allocation_minor' => 5000])->save();

        return $settlement;
    }

    public function test_approval_freezes_the_return_trip_at_the_original_delivery_fee(): void
    {
        $this->settlementWithShipping();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();

        $this->assertSame(5000, $record->fresh()->return_shipping_fee_minor);
        $this->parcel->forceFill(['shipping_fee_minor' => 9900])->save();
        $this->refund($record, '20.00');
        $this->assertSame(5000, $record->fresh()->return_shipping_fee_minor);
    }

    public function test_a_full_refund_charges_the_seller_refunded_shipping_and_the_return_trip(): void
    {
        $settlement = $this->settlementWithShipping();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();

        // Before the refund, earnings are held and only the return trip is owed.
        $this->assertSame(SellerSettlement::RELEASED, $settlement->fresh()->status);
        $this->assertSame(5000, SellerDebt::sole()->amount_minor);
        $this->assertSame(SellerDebt::RETURN_CHARGES, SellerDebt::sole()->reason);

        $this->refund($record, '150.00');

        // Items ₱100 refunded, ₱50 refunded shipping and ₱50 return trip charged: ₱100 owed.
        $this->assertSame(0, $settlement->fresh()->seller_payout_minor);
        $this->assertSame(10000, SellerDebt::sole()->amount_minor);
        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))->assertOk()
            ->assertSee('Buyer paid ₱150.00, including ₱50.00 shipping.')
            ->assertSee('Refunded original shipping charged to you: ₱50.00.')
            ->assertSee('Return shipping charged to you: ₱50.00 (the same as the original delivery fee).')
            ->assertSee('You keep nothing from this order.')
            ->assertSee('Return charges are more than this order earned, so ₱100.00 is taken from your next payouts');
    }

    public function test_a_partial_refund_takes_the_return_trip_from_what_the_seller_keeps(): void
    {
        $settlement = $this->settlementWithShipping();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();

        $this->refund($record, '20.00');

        // ₱80.00 kept less ₱8.00 commission = ₱72.00, less the ₱50.00 return trip = ₱22.00.
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertSame(2200, $settlement->fresh()->seller_payout_minor);
        $this->assertSame(0, SellerDebt::sole()->outstandingMinor());
        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))
            ->assertSee('You keep ₱22.00 from this order after ₱8.00 commission and ₱50.00 return charges.');
    }

    public function test_a_logistics_fault_charges_the_seller_no_shipping(): void
    {
        $settlement = $this->settlementWithShipping();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->setFault($record, ParcelReturn::FAULT_LOGISTICS)->assertSessionHasNoErrors();
        $this->refund($record, '150.00');

        $this->assertSame(9000, $settlement->fresh()->seller_payout_minor);
        $this->assertSame(0, SellerDebt::sole()->outstandingMinor());
        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))
            ->assertDontSee('Return shipping charged to you')
            ->assertSee('You keep ₱90.00 from this order after ₱10.00 commission.');
    }

    public function test_a_partial_refund_needs_a_reason_that_the_buyer_sees(): void
    {
        $this->pendingSettlement();
        $record = $this->approvedRecord();
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $base = ['reference' => 'R-1', 'amount' => '20.00', 'recipient' => 'Juana', 'paid_on' => now('Asia/Manila')->toDateString()];

        $this->actingAs($admin, 'admin')->post(route('admin.returns.refund', $record), $base)->assertSessionHasErrors('refund_reason');
        $this->post(route('admin.returns.refund', $record), $base + ['refund_reason' => 'short'])->assertSessionHasErrors('refund_reason');
        $this->assertNull($record->fresh()->refunded_at);

        $this->post(route('admin.returns.refund', $record), $base + ['refund_reason' => 'Only the charger was faulty.'])->assertSessionHasNoErrors();
        $this->assertSame('Only the charger was faulty.', $record->fresh()->refund_reason);
        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.orders.show', $this->parcel->order))->assertOk()
            ->assertSee('Why the refund is less than you paid: Only the charger was faulty.');
    }

    public function test_a_full_refund_needs_no_reason_and_the_buyer_is_told_shipping_is_covered(): void
    {
        $this->pendingSettlement();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.orders.show', $this->parcel->order))
            ->assertSee('Your refund covers the item and the original shipping fee. You do not pay for return shipping.');

        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin')
            ->post(route('admin.returns.refund', $record), ['reference' => 'R-1', 'amount' => '100.00', 'recipient' => 'Juana', 'paid_on' => now('Asia/Manila')->toDateString()])
            ->assertSessionHasNoErrors();
        $this->assertNull($record->fresh()->refund_reason);
    }

    public function test_a_failed_refund_is_voided_kept_in_history_and_recorded_again(): void
    {
        $settlement = $this->pendingSettlement();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->refund($record, '20.00');
        $this->assertSame(7200, $settlement->fresh()->seller_payout_minor);
        $admin = Admin::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin, 'admin')->post(route('admin.returns.refund.void', $record), ['void_reason' => 'short'])->assertSessionHasErrors('void_reason');
        $this->post(route('admin.returns.refund.void', $record), ['void_reason' => 'GCash transfer bounced: wrong number.', 'refund_revision' => $record->refundHistory()->sole()->id])->assertSessionHasNoErrors();

        $record->refresh();
        $this->assertNull($record->refunded_at);
        $this->assertNull($record->refund_reference);
        $voided = $record->events()->where('event_key', 'refund_voided')->sole();
        $this->assertSame(2000, $voided->metadata['amount_minor']);
        $this->assertSame('GCash transfer bounced: wrong number.', $voided->metadata['void_reason']);
        $this->assertDatabaseHas('operations_audit_logs', ['action' => 'return_refund_voided']);
        // Back on hold until the refund is paid again; who pays is unchanged.
        $this->assertSame(SellerSettlement::RELEASED, $settlement->fresh()->status);
        $this->assertSame(ParcelReturn::FAULT_SELLER, $record->fault_party);

        $this->actingAs($this->buyer, 'buyer')->get(route('buyer.orders.show', $this->parcel->order))
            ->assertSee('Your refund transfer did not go through.');
        $this->actingAs($admin, 'admin')->get(route('admin.returns.index'))
            ->assertSee('Voided refunds')->assertSee('GCash transfer bounced: wrong number.')
            ->assertSee(route('admin.returns.refund', $record), false);

        $this->refund($record, '20.00');
        $this->assertNotNull($record->fresh()->refunded_at);
        $this->assertTrue($record->events()->where('event_key', 'refund_recorded_2')->exists());
        $this->assertSame(7200, $settlement->fresh()->seller_payout_minor);
        $this->post(route('admin.returns.refund.void', $record->fresh()), ['void_reason' => 'Second transfer bounced as well.', 'refund_revision' => $record->refundHistory()->max('id')])->assertSessionHasNoErrors();
        $this->assertTrue($record->events()->where('event_key', 'refund_voided_2')->exists());
    }

    public function test_transit_damage_makes_the_delivering_company_repay_the_refund_and_admin_settles_it(): void
    {
        $settlement = $this->pendingSettlement();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->setFault($record, ParcelReturn::FAULT_LOGISTICS)->assertSessionHasNoErrors();
        $this->assertSame(0, LogisticsReturnCharge::query()->count());

        $this->refund($record, '100.00');

        $charge = LogisticsReturnCharge::sole();
        $this->assertSame(LogisticsReturnCharge::TO_ZEFANYA, $charge->direction);
        $this->assertSame(10000, $charge->amount_minor);
        $this->assertSame((int) CodRemittance::query()->value('logistics_provider_id'), (int) $charge->logistics_provider_id);

        $moderator = Admin::factory()->create(['role' => 'moderator']);
        $this->actingAs($moderator, 'admin')->post(route('admin.return-charges.settle', $charge), ['reference' => 'BANK-1'])->assertForbidden();
        $admin = Admin::factory()->create(['role' => 'super_admin']);
        $this->actingAs($admin, 'admin')->get(route('admin.returns.index'))
            ->assertSee('Refund for a parcel damaged in transit')->assertSee('Mark repaid');
        $this->post(route('admin.return-charges.settle', $charge), ['reference' => 'BANK-1'])->assertSessionHasNoErrors();
        $this->assertNotNull($charge->fresh()->settled_at);
        $this->assertSame('BANK-1', $charge->fresh()->settlement_reference);
        $this->post(route('admin.return-charges.settle', $charge), ['reference' => 'BANK-2'])->assertSessionHasErrors('reference');
        $this->assertSame(9000, $settlement->fresh()->seller_payout_minor);
    }

    public function test_voiding_removes_an_unsettled_transit_charge(): void
    {
        $this->pendingSettlement();
        $record = $this->approvedRecord();
        $this->setFault($record, ParcelReturn::FAULT_LOGISTICS)->assertSessionHasNoErrors();
        $this->refund($record, '100.00');
        $this->assertSame(1, LogisticsReturnCharge::query()->count());

        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin')
            ->post(route('admin.returns.refund.void', $record), ['void_reason' => 'Transfer bounced back to us.', 'refund_revision' => $record->refundHistory()->sole()->id])->assertSessionHasNoErrors();

        $this->assertSame(0, LogisticsReturnCharge::query()->count());
    }

    public function test_a_lower_debt_never_drops_below_what_the_seller_already_repaid(): void
    {
        $settlement = $this->pendingSettlement();
        $settlement->forceFill(['status' => SellerSettlement::PAID, 'seller_payout_minor' => 9000, 'paid_amount_minor' => 9000, 'pending_reason' => null])->save();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        SellerDebt::sole()->forceFill(['settled_minor' => 5000])->save();

        $this->refund($record, '20.00');

        $this->assertSame(5000, SellerDebt::sole()->amount_minor);
        $this->assertSame(0, SellerDebt::sole()->outstandingMinor());
    }

    public function test_a_refund_needs_an_amount_recipient_and_date_and_cannot_exceed_what_the_buyer_paid(): void
    {
        $this->pendingSettlement();
        $record = $this->approvedRecord();
        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin');
        $paid = $this->parcel->payment->amount_minor;
        $base = ['reference' => 'COD-REF-2001', 'amount' => '10.00', 'recipient' => 'Juana Cruz', 'paid_on' => now('Asia/Manila')->toDateString()];

        $this->post(route('admin.returns.refund', $record), ['reference' => 'COD-REF-2001'])
            ->assertSessionHasErrors(['amount', 'recipient', 'paid_on']);
        $this->post(route('admin.returns.refund', $record), array_merge($base, ['paid_on' => now('Asia/Manila')->addDay()->toDateString()]))
            ->assertSessionHasErrors('paid_on');
        $this->post(route('admin.returns.refund', $record), array_merge($base, ['amount' => (string) (($paid / 100) + 1)]))
            ->assertSessionHasErrors('amount');
        $this->assertNull($record->fresh()->refunded_at);
    }

    public function test_a_refund_proof_is_stored_privately_and_shown_to_admins_only(): void
    {
        Storage::fake('local');
        $this->pendingSettlement();
        $record = $this->approvedRecord();
        $this->actingAs(Admin::factory()->create(['role' => 'super_admin']), 'admin');

        $this->post(route('admin.returns.refund', $record), [
            'reference' => 'COD-REF-3001', 'amount' => '100.00', 'recipient' => 'Juana Cruz', 'paid_on' => now('Asia/Manila')->toDateString(),
            'proof' => UploadedFile::fake()->image('receipt.png'),
        ])->assertSessionHasNoErrors();

        $path = $record->fresh()->refund_proof_path;
        $this->assertNotNull($path);
        Storage::disk('local')->assertExists($path);
        $this->get(route('admin.returns.refund-proof', $record))->assertOk();
        $this->get(route('admin.returns.index'))->assertSee('View proof');
    }

    public function test_sel_ret_007_remittance_after_approval_does_not_reopen_pending_earnings(): void
    {
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))
            ->assertRedirect()->assertSessionHasNoErrors();

        $payment = $this->parcel->payment;
        $payment->forceFill([
            'method' => 'cod',
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'collected_at' => now(),
            'collected_amount_minor' => 10000,
            'amount_minor' => 10000,
        ])->save();
        $this->parcel->forceFill(['subtotal_minor' => 10000, 'shipping_fee_minor' => 0])->save();
        $provider = LogisticsProvider::factory()->create(['status' => 'approved']);
        $courier = Courier::factory()->create(['logistics_provider_id' => $provider->id]);
        $remittance = new CodRemittance;
        $remittance->forceFill([
            'payment_id' => $payment->id,
            'seller_order_id' => $this->parcel->id,
            'courier_id' => $courier->id,
            'logistics_provider_id' => $provider->id,
            'amount_minor' => 10000,
            'status' => CodRemittance::REMITTED_TO_LOGISTICS,
            'confirmed_by_id' => $provider->id,
            'confirmed_at' => now(),
            'received_amount_minor' => 10000,
            'zefanya_received_at' => now(),
        ])->save();

        $settlement = app(SellerSettlementService::class)->prepare($remittance);

        $this->assertSame(SellerSettlement::RELEASED, $settlement->status);
        $this->assertSame(0, $settlement->commission_minor);
        $this->assertSame(0, $settlement->seller_payout_minor);
        $this->assertNull($settlement->pending_reason);
    }
}
