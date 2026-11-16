<?php

namespace Tests\Feature\Admin;

use App\Enums\OrderActor;
use App\Enums\OrderStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderAddressReview;
use App\Models\Shared\SellerOrder;
use App\Notifications\Admin\AddressReviewNeeded;
use App\Notifications\Buyer\AddressCheckUpdate;
use App\Notifications\Seller\AddressCheckHold;
use App\Services\Admin\OrderAddressReviewService;
use App\Services\Seller\SellerOrderStatusService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Cross-region delivery addresses are confirmed by Admin phone call before pickup. */
class OrderAddressReviewTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const MANILA = '130000000';

    private const ABRA = '140100000';

    private const LAGUNA = '043400000';

    private const BULACAN = '031400000';

    private const DAVAO_DEL_SUR = '112400000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function shipment(string $sellerProvince, string $buyerProvince, OrderStatus $status = OrderStatus::Placed): SellerOrder
    {
        $seller = Seller::factory()->create(['province_code' => $sellerProvince, 'province_name' => 'Seller Province']);
        $buyer = Buyer::factory()->create();
        $order = Order::factory()->create(['buyer_id' => $buyer->id, 'shipping_address' => [
            'name' => 'Juan Dela Cruz', 'phone' => '09171234567', 'province' => 'Abra', 'province_code' => $buyerProvince,
            'full' => '1 Rizal St, Barangay 1, Bangued, Abra',
        ]]);

        return SellerOrder::factory()->status($status)->create(['order_id' => $order->id, 'seller_id' => $seller->id]);
    }

    private function flag(SellerOrder $parcel): ?OrderAddressReview
    {
        DB::transaction(fn () => app(OrderAddressReviewService::class)->flagOrder($parcel->order->fresh()));

        return OrderAddressReview::query()->where('seller_order_id', $parcel->id)->first();
    }

    private function staff(string $role = 'admin'): Admin
    {
        return Admin::factory()->create(['role' => $role]);
    }

    public function test_only_distant_regions_need_a_phone_check(): void
    {
        $service = app(OrderAddressReviewService::class);
        $this->assertSame(['13', '14'], $service->regionsNeedingCheck($this->shipment(self::MANILA, self::ABRA)));
        $this->assertSame(['04', '11'], $service->regionsNeedingCheck($this->shipment(self::LAGUNA, self::DAVAO_DEL_SUR)));
        $this->assertNull($service->regionsNeedingCheck($this->shipment(self::MANILA, self::LAGUNA)), 'Neighbouring regions');
        $this->assertNull($service->regionsNeedingCheck($this->shipment(self::LAGUNA, self::BULACAN)), 'Neighbouring regions');
        $this->assertNull($service->regionsNeedingCheck($this->shipment(self::LAGUNA, self::LAGUNA)), 'Same province');
        $this->assertNull($service->regionsNeedingCheck($this->shipment(self::MANILA, '')), 'Unknown region is not held');
        $this->assertSame('19', OrderAddressReviewService::region('153600000'), 'Older BARMM codes map to 19');
        $this->assertTrue(OrderAddressReviewService::nearby('11', '12'));
        $this->assertTrue(OrderAddressReviewService::nearby('12', '11'), 'Neighbours are symmetric');

        config(['address_review.enabled' => false]);
        $this->assertNull($service->regionsNeedingCheck($this->shipment(self::MANILA, self::ABRA)));
    }

    public function test_a_distant_order_is_flagged_once_and_everyone_is_told_after_commit(): void
    {
        Notification::fake();
        $admin = $this->staff();
        $support = $this->staff('support');
        $parcel = $this->shipment(self::MANILA, self::ABRA);
        $near = $this->shipment(self::MANILA, self::LAGUNA);

        $review = $this->flag($parcel);
        $this->flag($parcel);
        $this->assertNull($this->flag($near));

        $this->assertSame(1, OrderAddressReview::query()->count());
        $this->assertSame('pending', $review->status);
        $this->assertSame('09171234567', $review->contact_no);
        Notification::assertSentToTimes($parcel->order->buyer, AddressCheckUpdate::class, 1);
        Notification::assertSentTo($parcel->order->buyer, AddressCheckUpdate::class, function (AddressCheckUpdate $n) use ($parcel): bool {
            $message = $n->toArray($parcel->order->buyer)['message'];

            return str_contains($message, '0917•••4567') && ! str_contains($message, '09171234567');
        });
        Notification::assertSentTo($parcel->seller, AddressCheckHold::class, fn ($n) => $n->stage === 'held');
        Notification::assertSentTo($admin, AddressReviewNeeded::class);
        Notification::assertNotSentTo($support, AddressReviewNeeded::class);
        Notification::assertNothingSentTo($near->order->buyer);
    }

    public function test_a_held_shipment_cannot_be_sent_for_pickup_until_the_address_is_confirmed(): void
    {
        Notification::fake();
        $parcel = $this->shipment(self::MANILA, self::ABRA, OrderStatus::Preparing);
        $review = $this->flag($parcel);
        $statuses = app(SellerOrderStatusService::class);

        try {
            $statuses->transition($parcel, OrderStatus::ReadyForPickup, actor: OrderActor::Seller, actorId: $parcel->seller_id, source: 'web');
            $this->fail('A held shipment must not reach pickup.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame(OrderAddressReviewService::HOLD_MESSAGE, $e->getMessage());
        }
        $this->assertSame(OrderStatus::Preparing, $parcel->fresh()->status);
        // The Seller may still decline a held order through the existing action.
        $this->assertTrue(OrderActor::Seller->canTransition(OrderStatus::Preparing, OrderStatus::Cancelled));

        app(OrderAddressReviewService::class)->decide($review, $this->staff(), 'confirmed', 'reached_confirmed', null);
        $statuses->transition($parcel->fresh(), OrderStatus::ReadyForPickup, actor: OrderActor::Seller, actorId: $parcel->seller_id, source: 'web');
        $this->assertSame(OrderStatus::ReadyForPickup, $parcel->fresh()->status);
    }

    public function test_confirmation_needs_a_reached_buyer_and_not_confirmed_needs_a_note(): void
    {
        Notification::fake();
        $review = $this->flag($this->shipment(self::MANILA, self::ABRA));
        $service = app(OrderAddressReviewService::class);
        $admin = $this->staff();

        foreach ([['confirmed', 'no_answer', null, 'call_outcome'], ['not_confirmed', 'no_answer', 'short', 'decision_note']] as [$decision, $call, $note, $field]) {
            try {
                $service->decide($review, $admin, $decision, $call, $note);
                $this->fail("{$decision} with {$call} must be refused.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
        $this->assertSame('pending', $review->fresh()->status);
    }

    public function test_decisions_notify_once_are_audited_and_a_confirmation_is_final(): void
    {
        Notification::fake();
        $parcel = $this->shipment(self::MANILA, self::ABRA);
        $review = $this->flag($parcel);
        $service = app(OrderAddressReviewService::class);
        $admin = $this->staff('moderator');
        $buyer = $parcel->order->buyer;

        $this->assertTrue($service->decide($review, $admin, 'not_confirmed', 'no_answer', 'Called three times, no answer.'));
        $this->assertFalse($service->decide($review, $admin, 'not_confirmed', 'no_answer', 'Called three times, no answer.'), 'Replay sends nothing');
        $this->assertNotNull(app(OrderAddressReviewService::class)->holdFor($parcel), 'Not confirmed keeps the hold');

        $this->assertTrue($service->decide($review, $admin, 'confirmed', 'reached_confirmed', 'Buyer called back.'));
        $this->assertFalse($service->decide($review, $admin, 'not_confirmed', 'wrong_number', 'Trying to undo a confirmation.'), 'Confirmed is final');
        $this->assertSame('confirmed', $review->fresh()->status);
        $this->assertNull(app(OrderAddressReviewService::class)->holdFor($parcel));

        $stages = Notification::sent($buyer, AddressCheckUpdate::class)->pluck('stage')->all();
        $this->assertSame(['requested', 'not_confirmed', 'confirmed'], $stages);
        $this->assertSame(['held', 'not_confirmed', 'confirmed'], Notification::sent($parcel->seller, AddressCheckHold::class)->pluck('stage')->all());
        $this->assertSame(2, OperationsAuditLog::query()->where('action', 'order_address_review_decided')->count());
    }

    public function test_only_reviewing_staff_can_open_and_record_checks(): void
    {
        Notification::fake();
        $review = $this->flag($this->shipment(self::MANILA, self::ABRA));

        $this->get(route('admin.address-reviews.index'))->assertRedirect(route('admin.login'));
        $this->actingAs($this->staff('support'), 'admin')->get(route('admin.address-reviews.show', $review->id))->assertForbidden();
        $this->actingAs($this->staff('support'), 'admin')->post(route('admin.address-reviews.decide', $review->id), ['decision' => 'confirmed', 'call_outcome' => 'reached_confirmed'])->assertForbidden();

        $admin = $this->staff();
        $this->actingAs($admin, 'admin')->get(route('admin.address-reviews.index'))->assertOk()->assertSee($review->reference());
        $this->actingAs($admin, 'admin')->get(route('admin.address-reviews.show', $review->id))->assertOk()->assertSee('09171234567');
        $this->actingAs($admin, 'admin')->post(route('admin.address-reviews.decide', $review->id), ['decision' => 'confirmed', 'call_outcome' => 'reached_confirmed'])
            ->assertSessionHas('success');
        $this->assertSame('confirmed', $review->fresh()->status);
    }

    public function test_seller_and_buyer_pages_explain_the_hold(): void
    {
        Notification::fake();
        $parcel = $this->shipment(self::MANILA, self::ABRA, OrderStatus::Confirmed);
        $this->flag($parcel);

        $this->actingAs($parcel->seller, 'seller')->get(route('seller.orders.show', $parcel))->assertOk()->assertSee('On hold: delivery address check');
        $this->actingAs($parcel->order->buyer, 'buyer')->get(route('buyer.orders.show', $parcel->order))->assertOk()
            ->assertSee('Delivery address check')->assertSee('0917•••4567')->assertDontSee('09171234567 to confirm');
    }
}
