<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsReturnCharge;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerDebt;
use App\Models\Seller\SellerSettlement;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\StatusHistory;
use App\Notifications\Buyer\ReturnDisputeResolved;
use App\Notifications\Buyer\ReturnRequestDecided;
use App\Notifications\Seller\ReturnDisputed;
use App\Services\Logistics\ParcelHandoffService;
use App\Services\Logistics\ParcelReturnService;
use App\Services\Seller\SellerSettlementService;
use App\Services\Shared\CommissionFinalizationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $payment->forceFill([
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'collected_at' => now(),
            'collected_amount_minor' => 10000,
        ])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param array<string, mixed> $extra */
    private function requestReturn(array $extra = []): TestResponse
    {
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

    public function test_completed_shipment_accepts_only_eligible_defect_reasons_within_the_window(): void
    {
        $this->parcel->forceFill(['status' => OrderStatus::Completed])->save();
        $this->requestReturn(['reason' => 'Other'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->requestReturn(['reason' => 'Missing items or parts'])->assertCreated();
        $this->requestReturn(['reason' => 'Missing items or parts'])->assertOk();
        $this->assertSame(OrderStatus::Completed, $this->parcel->fresh()->status);
        $this->assertDatabaseCount('parcel_returns', 1);
        $this->assertNull(ParcelReturn::sole()->refunded_at);
    }

    public function test_admin_can_record_an_authorized_defect_claim_after_the_online_window(): void
    {
        $this->parcel->forceFill(['status' => OrderStatus::Completed])->save();
        $this->travel(8)->days();
        $this->requestReturn()->assertUnprocessable();
        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')
            ->post(route('admin.returns.defect-intake'), [
                'shipment_id' => $this->parcel->id,
                'reason' => 'Item is defective',
                'description' => 'The item failed on first use.',
                'support_reason' => 'Support verified the Buyer’s warranty report.',
            ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('parcel_return_events', ['event_key' => 'support_exception_accepted']);
        $this->assertSame(OrderStatus::Completed, $this->parcel->fresh()->status);
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
        $this->assertSame(OrderStatus::Delivered, $this->parcel->fresh()->status);
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
            ->assertSee('Order Received');
    }

    public function test_a_rejected_return_lets_the_order_complete_automatically_and_cannot_be_requested_again(): void
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

    public function test_an_open_or_approved_return_still_blocks_automatic_completion(): void
    {
        $record = $this->record();

        Carbon::setTestNow(now()->addDays(8));
        $this->artisan('returns:auto-approve-overdue')->assertSuccessful();
        $this->artisan('orders:auto-complete-receipts')->assertSuccessful();

        $this->assertSame(ParcelReturn::AUTO_APPROVED, $record->fresh()->seller_decision);
        $this->assertSame(OrderStatus::Delivered, $this->parcel->fresh()->status);
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

        app(ParcelReturnService::class)->resolveDispute($record, Admin::factory()->create(['role' => 'admin']), ParcelReturn::DISPUTE_UPHELD, 'The photos do not show any damage.');

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
        app(ParcelReturnService::class)->resolveDispute($record, Admin::factory()->create(['role' => 'admin']), ParcelReturn::DISPUTE_OVERTURNED, 'The photos match the description.');

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
        $admin = Admin::factory()->create(['role' => 'admin']);
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
            ->assertSee('Order Received');

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
            'commission_bps' => 500,
            'commission_minor' => 500,
            'seller_net_minor' => 9500,
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
        $this->assertSame(0, $this->parcel->commission_bps);
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
        $this->assertSame(500, $this->parcel->fresh()->commission_minor);
        $this->assertSame(9500, $this->parcel->fresh()->seller_net_minor);

        $this->reject($record);
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertSame(SellerSettlement::COMMISSION_FINALIZATION_MISSING, $settlement->fresh()->pending_reason);
        $this->assertSame(500, $this->parcel->fresh()->commission_minor);
    }

    public function test_sel_ret_007_a_recorded_refund_releases_pending_earnings_once(): void
    {
        $settlement = $this->pendingSettlement();
        $record = $this->record();
        $this->approveForRefund($record);
        $admin = Admin::factory()->create(['role' => 'admin']);

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
        // ₱50.25 of ₱100.00 refunded: the Seller keeps ₱49.75 of items, less 5% commission (₱2.49).
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertNull($settlement->fresh()->pending_reason);
        $this->assertSame(249, $settlement->fresh()->commission_minor);
        $this->assertSame(4726, $settlement->fresh()->seller_payout_minor);
        $this->assertSame(249, $this->parcel->fresh()->commission_minor);
        $this->assertSame(4726, $this->parcel->fresh()->seller_net_minor);

        $this->post(route('admin.returns.refund', $record), ['reference' => 'COD-REF-1002'] + $refund)
            ->assertSessionHasErrors('refund');
        $this->assertSame('COD-REF-1001', $record->fresh()->refund_reference);
        $this->assertSame(4726, $settlement->fresh()->seller_payout_minor);
    }

    private function refund(ParcelReturn $record, string $amount): void
    {
        if ($record->fresh()->awaitingSellerDecision()) {
            $this->approveForRefund($record);
        }

        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')
            ->post(route('admin.returns.refund', $record), ['reference' => 'COD-REF-'.$record->id, 'amount' => $amount, 'recipient' => 'Juana Cruz', 'paid_on' => now('Asia/Manila')->toDateString(), 'refund_reason' => 'Only part of the order was faulty.'])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    private function approveForRefund(ParcelReturn $record): void
    {
        $this->actingAs($this->seller, 'seller')
            ->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
    }

    public function test_a_full_refund_leaves_the_seller_nothing(): void
    {
        $settlement = $this->pendingSettlement();
        $this->refund($this->record(), '100.00');

        $this->assertSame(SellerSettlement::RELEASED, $settlement->fresh()->status);
        $this->assertSame(0, $settlement->fresh()->seller_payout_minor);
        $this->assertSame(0, $this->parcel->fresh()->seller_net_minor);
    }

    public function test_a_partial_refund_after_approval_gives_the_seller_back_the_unrefunded_part(): void
    {
        $settlement = $this->pendingSettlement();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->assertSame(SellerSettlement::RELEASED, $settlement->fresh()->status);

        $this->refund($record, '20.00');

        // ₱80.00 of items kept, less ₱4.00 commission.
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertSame(400, $settlement->fresh()->commission_minor);
        $this->assertSame(7600, $settlement->fresh()->seller_payout_minor);

        // Completing the order later keeps the same split.
        $this->parcel->forceFill(['status' => OrderStatus::Completed])->save();
        app(CommissionFinalizationService::class)->finalize($this->parcel->fresh());
        $this->assertSame(7600, $this->parcel->fresh()->seller_net_minor);
        $this->assertSame(7600, $settlement->fresh()->seller_payout_minor);
    }

    public function test_a_partial_refund_after_payout_charges_the_seller_only_what_was_refunded(): void
    {
        $settlement = $this->pendingSettlement();
        $settlement->forceFill(['status' => SellerSettlement::PAID, 'seller_payout_minor' => 9500, 'paid_amount_minor' => 9500, 'pending_reason' => null])->save();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->assertSame(9500, SellerDebt::sole()->amount_minor);

        $this->refund($record, '20.00');

        // Paid ₱95.00, keeps ₱76.00, so owes back ₱19.00 (₱20.00 refunded less the ₱1.00 commission on it).
        $this->assertSame(1900, SellerDebt::sole()->amount_minor);
        $this->assertSame(SellerSettlement::PAID, $settlement->fresh()->status);
    }

    private function setFault(ParcelReturn $record, string $party, string $note = 'Courier crushed the box in transit.'): TestResponse
    {
        return $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')
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
        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')->get(route('admin.returns.index'))->assertOk()
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
        $this->assertSame(9500, $settlement->fresh()->seller_payout_minor);
        $this->assertSame(500, $settlement->fresh()->commission_minor);
        $this->assertDatabaseHas('operations_audit_logs', ['action' => 'return_fault_set']);
        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))
            ->assertSee('You are not charged for this return.')
            ->assertSee('You keep ₱95.00 from this order after ₱5.00 commission.');
        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')->get(route('admin.returns.index'))
            ->assertSee('Logistics (damaged in transit) · set by an admin')
            ->assertSee('Courier crushed the box in transit.')
            ->assertDontSee('Save who pays');
    }

    public function test_a_logistics_fault_after_payout_cancels_the_sellers_debt(): void
    {
        $settlement = $this->pendingSettlement();
        $settlement->forceFill(['status' => SellerSettlement::PAID, 'seller_payout_minor' => 9500, 'paid_amount_minor' => 9500, 'pending_reason' => null])->save();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->assertSame(9500, SellerDebt::sole()->amount_minor);

        $this->setFault($record, ParcelReturn::FAULT_LOGISTICS)->assertSessionHasNoErrors();

        $this->assertSame(0, SellerDebt::sole()->outstandingMinor());
        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))->assertDontSee('taken back from your next payouts');
    }

    public function test_other_as_the_reason_blocks_the_refund_until_an_admin_decides_who_pays(): void
    {
        $this->pendingSettlement();
        $this->requestReturn(['reason' => 'Other'])->assertCreated();
        $record = ParcelReturn::sole();
        $admin = Admin::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'admin')->get(route('admin.returns.index'))
            ->assertSee('The buyer chose "Other" as the reason.', false)
            ->assertSee('Recording the refund is available after you decide who pays.')
            ->assertDontSee(route('admin.returns.refund', $record), false);
        $this->post(route('admin.returns.refund', $record), ['reference' => 'R-1', 'amount' => '10.00', 'recipient' => 'Juana', 'paid_on' => now('Asia/Manila')->toDateString()])
            ->assertSessionHasErrors('refund');
        $this->assertNull($record->fresh()->refunded_at);

        $this->setFault($record, ParcelReturn::FAULT_SELLER, 'Photos show the wrong colour was sent.')->assertSessionHasNoErrors();
        $this->refund($record, '10.00');
        $this->assertNotNull($record->fresh()->refunded_at);
    }

    public function test_who_pays_needs_a_reason_and_is_locked_once_refunded(): void
    {
        $this->pendingSettlement();
        $record = $this->record();

        $this->setFault($record, ParcelReturn::FAULT_LOGISTICS, 'short')->assertSessionHasErrors('fault_note');
        $this->setFault($record, 'buyer')->assertSessionHasErrors('fault_party');
        $this->assertNull($record->fresh()->fault_party);

        $this->refund($record, '100.00');
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
            ->assertSee('You keep ₱76.00 from this order after ₱4.00 commission.');
    }

    /** ₱100.00 of items plus ₱50.00 shipping; the buyer paid ₱150.00. */
    private function settlementWithShipping(): SellerSettlement
    {
        $settlement = $this->pendingSettlement();
        $this->parcel->forceFill(['shipping_fee_minor' => 5000])->save();
        $this->parcel->payment->forceFill(['amount_minor' => 15000, 'collected_amount_minor' => 15000])->save();
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

        // ₱80.00 kept less ₱4.00 commission = ₱76.00, less the ₱50.00 return trip = ₱26.00.
        $this->assertSame(SellerSettlement::PENDING_PAYOUT, $settlement->fresh()->status);
        $this->assertSame(2600, $settlement->fresh()->seller_payout_minor);
        $this->assertSame(0, SellerDebt::sole()->outstandingMinor());
        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))
            ->assertSee('You keep ₱26.00 from this order after ₱4.00 commission and ₱50.00 return charges.');
    }

    public function test_a_logistics_fault_charges_the_seller_no_shipping(): void
    {
        $settlement = $this->settlementWithShipping();
        $record = $this->record();
        $this->actingAs($this->seller, 'seller')->post(route('seller.returns.approve', $record))->assertSessionHasNoErrors();
        $this->setFault($record, ParcelReturn::FAULT_LOGISTICS)->assertSessionHasNoErrors();
        $this->refund($record, '150.00');

        $this->assertSame(9500, $settlement->fresh()->seller_payout_minor);
        $this->assertSame(0, SellerDebt::sole()->outstandingMinor());
        $this->actingAs($this->seller, 'seller')->get(route('seller.returns.index'))
            ->assertDontSee('Return shipping charged to you')
            ->assertSee('You keep ₱95.00 from this order after ₱5.00 commission.');
    }

    public function test_a_partial_refund_needs_a_reason_that_the_buyer_sees(): void
    {
        $this->pendingSettlement();
        $record = $this->record();
        $this->approveForRefund($record);
        $admin = Admin::factory()->create(['role' => 'admin']);
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

        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')
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
        $this->assertSame(7600, $settlement->fresh()->seller_payout_minor);
        $admin = Admin::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'admin')->post(route('admin.returns.refund.void', $record), ['void_reason' => 'short'])->assertSessionHasErrors('void_reason');
        $this->post(route('admin.returns.refund.void', $record), ['void_reason' => 'GCash transfer bounced: wrong number.'])->assertSessionHasNoErrors();

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
        $this->assertSame(7600, $settlement->fresh()->seller_payout_minor);
        $this->post(route('admin.returns.refund.void', $record->fresh()), ['void_reason' => 'Second transfer bounced as well.'])->assertSessionHasNoErrors();
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
        $admin = Admin::factory()->create(['role' => 'admin']);
        $this->actingAs($admin, 'admin')->get(route('admin.returns.index'))
            ->assertSee('Refund for a parcel damaged in transit')->assertSee('Mark repaid');
        $this->post(route('admin.return-charges.settle', $charge), ['reference' => 'BANK-1'])->assertSessionHasNoErrors();
        $this->assertNotNull($charge->fresh()->settled_at);
        $this->assertSame('BANK-1', $charge->fresh()->settlement_reference);
        $this->post(route('admin.return-charges.settle', $charge), ['reference' => 'BANK-2'])->assertSessionHasErrors('reference');
        $this->assertSame(9500, $settlement->fresh()->seller_payout_minor);
    }

    public function test_voiding_removes_an_unsettled_transit_charge(): void
    {
        $this->pendingSettlement();
        $record = $this->record();
        $this->setFault($record, ParcelReturn::FAULT_LOGISTICS)->assertSessionHasNoErrors();
        $this->refund($record, '100.00');
        $this->assertSame(1, LogisticsReturnCharge::query()->count());

        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')
            ->post(route('admin.returns.refund.void', $record), ['void_reason' => 'Transfer bounced back to us.'])->assertSessionHasNoErrors();

        $this->assertSame(0, LogisticsReturnCharge::query()->count());
    }

    public function test_a_lower_debt_never_drops_below_what_the_seller_already_repaid(): void
    {
        $settlement = $this->pendingSettlement();
        $settlement->forceFill(['status' => SellerSettlement::PAID, 'seller_payout_minor' => 9500, 'paid_amount_minor' => 9500, 'pending_reason' => null])->save();
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
        $record = $this->record();
        $this->approveForRefund($record);
        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin');
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
        $record = $this->record();
        $this->approveForRefund($record);
        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin');

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

    public function test_refund_requires_both_a_decision_and_confirmed_collection(): void
    {
        $record = $this->record();
        $admin = Admin::factory()->create(['role' => 'admin']);
        $url = route('admin.returns.refund', $record);
        $payload = ['reference' => 'REF-1', 'amount' => '100.00', 'recipient' => 'Juana', 'paid_on' => now('Asia/Manila')->toDateString()];

        $this->actingAs($admin, 'admin')->post($url, $payload)->assertSessionHasErrors('refund');
        $this->approveForRefund($record);
        $this->parcel->payment->forceFill(['collected_at' => null, 'collected_amount_minor' => null])->save();
        $this->actingAs($admin, 'admin')->post($url, $payload)->assertSessionHasErrors('refund');

        $this->assertNull($record->fresh()->refunded_at);
        $this->assertDatabaseCount('seller_debts', 0);
    }

    public function test_a_rejected_return_cannot_be_refunded_even_when_payment_was_collected(): void
    {
        $record = $this->record();
        $this->reject($record)->assertSessionHasNoErrors();

        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')
            ->post(route('admin.returns.refund', $record), [
                'reference' => 'REF-1', 'amount' => '100.00', 'recipient' => 'Juana',
                'paid_on' => now('Asia/Manila')->toDateString(),
            ])->assertSessionHasErrors('refund');

        $this->assertNull($record->fresh()->refunded_at);
        $this->assertDatabaseCount('seller_debts', 0);
    }

    public function test_refund_amount_is_capped_by_collected_funds_even_when_the_order_amount_is_higher(): void
    {
        $record = $this->record();
        $this->approveForRefund($record);
        $this->parcel->payment->forceFill(['amount_minor' => 15000])->save();
        $this->actingAs(Admin::factory()->create(['role' => 'admin']), 'admin')
            ->post(route('admin.returns.refund', $record), [
                'reference' => 'REF-1', 'amount' => '100.01', 'recipient' => 'Juana',
                'paid_on' => now('Asia/Manila')->toDateString(),
            ])->assertSessionHasErrors('amount');

        $this->assertNull($record->fresh()->refunded_at);
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
