<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Enums\ParcelReturnStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Seller\Seller;
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
        $admin = Admin::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'admin')->get(route('admin.returns.index'))
            ->assertOk()
            ->assertSee(route('admin.returns.refund', $record), false);

        $this->post(route('admin.returns.refund', $record), ['reference' => 'COD-REF-1001'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('COD-REF-1001', $record->fresh()->refund_reference);
        $this->assertNotNull($record->fresh()->refunded_at);
        $this->assertSame(SellerSettlement::RELEASED, $settlement->fresh()->status);
        $this->assertSame(0, $settlement->fresh()->commission_minor);
        $this->assertSame(0, $settlement->fresh()->seller_payout_minor);
        $this->assertSame(0, $this->parcel->fresh()->seller_net_minor);

        $this->post(route('admin.returns.refund', $record), ['reference' => 'COD-REF-1002'])
            ->assertSessionHasErrors('refund');
        $this->assertSame('COD-REF-1001', $record->fresh()->refund_reference);
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
