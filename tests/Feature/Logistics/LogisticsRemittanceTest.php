<?php

namespace Tests\Feature\Logistics;

use App\Enums\ParcelReturnStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Admin\PlatformSetting;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsRemittance;
use App\Models\Logistics\OriginSharePayout;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Rider\RiderEarning;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Notifications\Admin\LogisticsPaymentRecorded;
use App\Notifications\Logistics\LogisticsPaymentDecided;
use App\Notifications\Logistics\OriginSharePaymentRecorded;
use App\Services\Admin\PlatformFinanceService;
use App\Services\Logistics\LogisticsFinanceService;
use App\Services\Logistics\LogisticsRemittanceService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LogisticsRemittanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now(), 'business_name' => 'Ninja Express']);
    }

    private function parcel(int $cod, int $shipping, string $status = CodRemittance::REMITTED_TO_LOGISTICS, ?LogisticsProvider $provider = null, ?LogisticsProvider $carrier = null): CodRemittance
    {
        $order = SellerOrder::factory()->create(['shipping_fee_minor' => $shipping, 'tracking_number' => 'TRK-'.fake()->unique()->numerify('######')]);
        $order->forceFill(['carrier_provider_id' => $carrier?->id])->save();
        $remittance = new CodRemittance;
        $remittance->forceFill([
            'payment_id' => Payment::factory()->create(['seller_order_id' => $order->id])->id,
            'seller_order_id' => $order->id,
            'courier_id' => Courier::factory()->create()->id,
            'logistics_provider_id' => ($provider ?? $this->provider)->id,
            'amount_minor' => $cod,
            'status' => $status,
        ])->save();

        return $remittance;
    }

    /** Sends every ready parcel unless told which ones, like the page's ticked-by-default list. */
    private function remit(int $expected, string $reference = 'GCASH-1001', ?array $parcels = null)
    {
        $parcels ??= CodRemittance::query()->where('logistics_provider_id', $this->provider->id)
            ->where('status', CodRemittance::REMITTED_TO_LOGISTICS)->whereNull('logistics_remittance_id')->pluck('id')->all();

        return $this->actingAs($this->provider, 'logistics')
            ->from(route('logistics.earnings.index'))
            ->post(route('logistics.earnings.remit'), ['parcels' => $parcels, 'expected_amount_minor' => $expected, 'reference' => $reference]);
    }

    private function admin(string $role = 'admin'): Admin
    {
        return Admin::factory()->create(['role' => $role]);
    }

    public function test_earnings_page_shows_each_shipping_fee_and_what_is_owed(): void
    {
        $this->parcel(106900, 6900);
        $this->parcel(50000, 4900);
        $this->parcel(20000, 5000, CodRemittance::PENDING_REMITTANCE);
        $this->parcel(99900, 100, provider: LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]));

        $this->actingAs($this->provider, 'logistics')->get(route('logistics.earnings.index'))
            ->assertOk()
            ->assertSee('Shipping fees')
            ->assertSee('₱168.00')       // earned: 69 + 49 + 50
            ->assertSee('₱1,451.00')     // to send: 1,569 collected minus 118 shipping
            ->assertSee('₱200.00')       // still with riders
            ->assertSee('Cash with rider')
            ->assertSee('To send to Zefanya')
            ->assertDontSee('₱999.00');
    }

    public function test_recording_a_payment_covers_every_ready_parcel_at_the_right_amount(): void
    {
        $first = $this->parcel(106900, 6900);
        $second = $this->parcel(50000, 4900);
        $withRider = $this->parcel(20000, 5000, CodRemittance::PENDING_REMITTANCE);

        $this->remit(145100)->assertSessionHasNoErrors()->assertSessionHas('success');

        $payment = LogisticsRemittance::query()->sole();
        $this->assertSame(LogisticsRemittance::SUBMITTED, $payment->status);
        $this->assertSame(2, $payment->parcel_count);
        $this->assertSame(156900, $payment->cod_total_minor);
        $this->assertSame(11800, $payment->shipping_total_minor);
        $this->assertSame(145100, $payment->amount_minor);
        $this->assertSame($payment->id, $first->fresh()->logistics_remittance_id);
        $this->assertSame($payment->id, $second->fresh()->logistics_remittance_id);
        $this->assertNull($withRider->fresh()->logistics_remittance_id);
        $this->assertNull($first->fresh()->zefanya_received_at);
    }

    public function test_a_cross_provider_shipping_fee_is_split_forty_to_the_origin_and_sixty_to_the_destination(): void
    {
        $carrier = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $crossProvider = $this->parcel(50000, 4900, carrier: $carrier);
        $ownCarrier = $this->parcel(30000, 5000, carrier: $this->provider);

        $this->assertSame(1960, $crossProvider->fresh()->originShareMinor());
        $this->assertSame(2940, $crossProvider->fresh()->keptShippingMinor());
        $this->assertSame(0, $ownCarrier->fresh()->originShareMinor());

        $this->actingAs($this->provider, 'logistics')->get(route('logistics.earnings.index'))
            ->assertOk()
            ->assertSee('60% share')
            ->assertSee('₱79.40')        // earned: 60% of 49 + all of 50
            ->assertSee('">₱720.60</span> to Zefanya', false); // 500 - 29.40 + 300 - 50

        $this->remit(72060)->assertSessionHasNoErrors();

        $payment = LogisticsRemittance::query()->sole();
        $this->assertSame(80000, $payment->cod_total_minor);
        $this->assertSame(7940, $payment->shipping_total_minor);
        $this->assertSame(72060, $payment->amount_minor);
        $this->assertTrue($crossProvider->fresh()->isCrossProvider());
        $this->assertFalse($ownCarrier->fresh()->isCrossProvider());

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->get(route('admin.logistics-remittances.index'))
            ->assertOk()
            ->assertSee('Includes ₱19.60 for the origin carrier')
            ->assertViewHas('originShares', ['parcels' => 1, 'shareMinor' => 1960, 'receivedMinor' => 0, 'paidMinor' => 0]);

        $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))
            ->assertViewHas('settlements', fn ($rows) => $rows->first()['shippingMinor'] === 7940 && $rows->first()['owedMinor'] === 72060);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.logistics-remittances.confirm', $payment), ['received_amount' => '720.60'])
            ->assertSessionHasNoErrors();

        // Once Zefanya has the cash, it owes the origin carrier its 40%.
        $this->actingAs($carrier, 'logistics')->get(route('logistics.earnings.index'))
            ->assertOk()
            ->assertViewHas('originShares', ['parcels' => 1, 'shareMinor' => 1960, 'receivedMinor' => 1960, 'paidMinor' => 0])
            ->assertSee('₱19.60');
    }

    private function payOrigin(LogisticsProvider $origin, int $throughId, int $expected, string $reference = 'BDO-500')
    {
        return $this->actingAs($this->admin(), 'admin')
            ->from(route('admin.logistics-remittances.index'))
            ->post(route('admin.logistics-remittances.pay-origin', $origin), [
                'through_id' => $throughId,
                'expected_amount_minor' => $expected,
                'reference' => $reference,
                'paid_on' => now()->toDateString(),
            ]);
    }

    public function test_admin_pays_an_origin_carrier_its_share_once_and_only_after_zefanya_received_the_cash(): void
    {
        $carrier = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now(), 'business_name' => 'Origin Couriers']);
        $parcel = $this->parcel(50000, 4900, carrier: $carrier);
        $this->remit(47060)->assertSessionHasNoErrors();

        // Cash not yet confirmed by Zefanya: nothing can be paid out.
        $this->payOrigin($carrier, $parcel->id, 1960)->assertSessionHasErrors('origin');

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.logistics-remittances.confirm', LogisticsRemittance::query()->sole()), ['received_amount' => '470.60'])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->admin(), 'admin')->get(route('admin.logistics-remittances.index'))
            ->assertOk()
            ->assertSee('Owed to origin carriers')
            ->assertSee('Origin Couriers')
            ->assertViewHas('originOwed', fn ($rows) => $rows->sole()['amountMinor'] === 1960);

        $this->payOrigin($carrier, $parcel->id, 1000)->assertSessionHasErrors('origin');
        $this->payOrigin($carrier, $parcel->id, 1960)->assertSessionHasNoErrors()->assertSessionHas('success');

        $payout = OriginSharePayout::query()->sole();
        $this->assertSame($carrier->id, $payout->logistics_provider_id);
        $this->assertSame(1960, $payout->amount_minor);
        $this->assertSame(1, $payout->parcel_count);
        $this->assertSame('BDO-500', $payout->reference);
        $this->assertSame(OriginSharePayout::PAID, $payout->status);
        $this->assertSame($payout->id, $parcel->fresh()->origin_share_payout_id);
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'origin_share_paid')->count());

        // Replays and a reused reference are refused.
        $this->payOrigin($carrier, $parcel->id, 1960)->assertSessionHasErrors('reference');
        $this->payOrigin($carrier, $parcel->id, 1960, 'BDO-501')->assertSessionHasErrors('origin');
        $this->assertSame(1, OriginSharePayout::query()->count());

        $this->actingAs($carrier, 'logistics')->get(route('logistics.earnings.index'))
            ->assertOk()
            ->assertViewHas('originShares', fn ($shares) => $shares['paidMinor'] === 1960);
    }

    public function test_a_saved_split_does_not_change_when_the_parcel_or_rule_inputs_change(): void
    {
        $carrier = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $parcel = $this->parcel(50000, 4900, carrier: $carrier);
        $parcel->snapshotAllocation();
        $parcel->save();
        $this->assertSame(2940, $parcel->fresh()->kept_shipping_minor);
        $this->assertSame(1960, $parcel->fresh()->origin_share_minor);

        // Inputs the live rule reads change after delivery; the saved split stays.
        $parcel->sellerOrder->forceFill(['carrier_provider_id' => null])->save();
        $fresh = $parcel->fresh();
        $fresh->snapshotAllocation();
        $this->assertSame(2940, $fresh->keptShippingMinor());
        $this->assertSame(1960, $fresh->originShareMinor());
        $this->assertSame(47060, app(LogisticsRemittanceService::class)->totals($this->provider)['ready']['dueMinor']);
    }

    public function test_the_snapshot_backfill_keeps_the_rule_each_sent_payment_used(): void
    {
        $carrier = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $legacy = $this->parcel(50000, 4900, carrier: $carrier);
        $split = $this->parcel(50000, 4900, carrier: $carrier);
        $unsent = $this->parcel(50000, 4900, carrier: $carrier);

        // One payment sent under the old rule (full fee to Zefanya), one under 40/60.
        foreach ([[$legacy, 0, 'OLD-1'], [$split, 2940, 'NEW-1']] as [$parcel, $shipping, $reference]) {
            $payment = new LogisticsRemittance;
            $payment->forceFill([
                'logistics_provider_id' => $this->provider->id, 'parcel_count' => 1, 'cod_total_minor' => 50000,
                'shipping_total_minor' => $shipping, 'amount_minor' => 50000 - $shipping,
                'reference' => $reference, 'status' => LogisticsRemittance::CONFIRMED,
            ])->save();
            $parcel->forceFill(['logistics_remittance_id' => $payment->id, 'zefanya_received_at' => now()])->save();
        }

        $migration = require database_path('migrations/2026_11_03_100000_add_allocation_snapshot_to_cod_remittances.php');
        Schema::table('cod_remittances', fn ($table) => $table->dropConstrainedForeignId('origin_share_payout_id'));
        $migration->down();
        $migration->up();
        Schema::table('cod_remittances', fn ($table) => $table->foreignId('origin_share_payout_id')->nullable()->constrained('origin_share_payouts'));

        $this->assertSame([0, 0], [$legacy->fresh()->kept_shipping_minor, $legacy->fresh()->origin_share_minor]);
        $this->assertSame([2940, 1960], [$split->fresh()->kept_shipping_minor, $split->fresh()->origin_share_minor]);
        $this->assertSame([2940, 1960], [$unsent->fresh()->kept_shipping_minor, $unsent->fresh()->origin_share_minor]);
    }

    public function test_a_changed_amount_or_nothing_to_send_stops_the_payment(): void
    {
        $this->remit(100)->assertSessionHasErrors('parcels');

        $this->parcel(50000, 4900);
        $this->remit(100)->assertSessionHasErrors('remittance');

        $this->assertSame(0, LogisticsRemittance::query()->count());
    }

    public function test_a_parcel_cannot_be_paid_twice_and_a_reference_cannot_be_reused(): void
    {
        $this->parcel(50000, 4900);
        $first = CodRemittance::query()->sole();
        $this->remit(45100)->assertSessionHasNoErrors();
        $this->remit(45100, 'GCASH-2002', [$first->id])->assertSessionHasErrors('remittance');

        $this->parcel(30000, 5000);
        $this->remit(25000)->assertSessionHasErrors('reference');

        $this->assertSame(1, LogisticsRemittance::query()->count());
    }

    public function test_a_company_can_pay_for_only_the_parcels_it_chooses(): void
    {
        $chosen = $this->parcel(50000, 4900);
        $later = $this->parcel(30000, 5000);

        $this->remit(45100, 'GCASH-PART', [$chosen->id])->assertSessionHasNoErrors();

        $payment = LogisticsRemittance::query()->sole();
        $this->assertSame(1, $payment->parcel_count);
        $this->assertSame(45100, $payment->amount_minor);
        $this->assertSame($payment->id, $chosen->fresh()->logistics_remittance_id);
        $this->assertNull($later->fresh()->logistics_remittance_id);

        $this->get(route('logistics.earnings.index'))->assertOk()->assertSee('">₱250.00</span> to Zefanya', false);
    }

    public function test_a_company_cannot_pay_for_parcels_that_are_not_ready_or_not_its_own(): void
    {
        $foreign = $this->parcel(50000, 4900, provider: LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]));
        $withRider = $this->parcel(20000, 5000, CodRemittance::PENDING_REMITTANCE);
        $mine = $this->parcel(30000, 5000);

        $this->remit(70100, 'GCASH-X', [$mine->id, $foreign->id])->assertSessionHasErrors('remittance');
        $this->remit(40000, 'GCASH-Y', [$mine->id, $withRider->id])->assertSessionHasErrors('remittance');

        $this->assertSame(0, LogisticsRemittance::query()->count());
        $this->assertNull($foreign->fresh()->logistics_remittance_id);
    }

    public function test_recording_and_deciding_a_payment_tells_the_other_side(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $this->admin('super_admin');
        $moderator = $this->admin('moderator');

        $this->parcel(50000, 4900);
        $this->remit(45100);
        Notification::assertSentTo($admin, LogisticsPaymentRecorded::class);
        Notification::assertNotSentTo($moderator, LogisticsPaymentRecorded::class);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.logistics-remittances.confirm', LogisticsRemittance::query()->sole()), ['received_amount' => '451.00']);
        Notification::assertSentTo($this->provider, LogisticsPaymentDecided::class,
            fn (LogisticsPaymentDecided $notification): bool => $notification->remittance->status === LogisticsRemittance::CONFIRMED);

        $this->parcel(30000, 5000);
        $this->remit(25000, 'GCASH-2');
        $this->actingAs($admin, 'admin')
            ->post(route('admin.logistics-remittances.reject', LogisticsRemittance::query()->where('reference', 'GCASH-2')->sole()), ['reason' => 'Nothing arrived']);
        Notification::assertSentTo($this->provider, LogisticsPaymentDecided::class,
            fn (LogisticsPaymentDecided $notification): bool => $notification->remittance->status === LogisticsRemittance::REJECTED);
    }

    public function test_a_decision_reaches_the_company_notification_bell(): void
    {
        $this->parcel(50000, 4900);
        $this->remit(45100);
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.logistics-remittances.reject', LogisticsRemittance::query()->sole()), ['reason' => 'Nothing arrived']);

        $this->actingAs($this->provider, 'logistics')->getJson(route('logistics.notifications.recent'))->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.url', route('logistics.earnings.index'));
    }

    public function test_the_earnings_page_shows_where_to_send_the_money(): void
    {
        $this->parcel(50000, 4900);
        $page = fn () => $this->actingAs($this->provider, 'logistics')->get(route('logistics.earnings.index'))->assertOk();

        $page()->assertSee("Zefanya hasn't added its payment details yet", false);

        PlatformSetting::query()->where('key', 'remittance_payment_details')->update(['value' => 'GCash: Zefanya Inc., 0917 000 0000']);

        $page()->assertSee('GCash: Zefanya Inc., 0917 000 0000')->assertDontSee("hasn't added its payment details", false);
    }

    public function test_admin_can_set_the_payment_details_on_the_settings_page(): void
    {
        $setting = PlatformSetting::query()->where('key', 'remittance_payment_details')->sole();

        $this->actingAs($this->admin(), 'admin')->get(route('admin.settings.index'))
            ->assertOk()->assertSee('Where Logistics companies send payments');

        $this->patch(route('admin.settings.update', $setting), ['value' => 'GCash: 0917 000 0000', 'expected_value' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame('GCash: 0917 000 0000', $setting->fresh()->value);
    }

    public function test_a_returned_parcel_is_labelled_and_still_counts_as_owed(): void
    {
        $parcel = $this->parcel(50000, 4900);
        ParcelReturn::query()->forceCreate([
            'seller_order_id' => $parcel->seller_order_id,
            'status' => ParcelReturnStatus::Requested->value,
            'reason' => 'Item arrived damaged',
            'requested_at' => now(),
            'seller_decision' => ParcelReturn::APPROVED,
        ]);

        $this->actingAs($this->provider, 'logistics')->get(route('logistics.earnings.index'))
            ->assertOk()->assertSee('Returned by buyer')->assertSee('₱451.00');

        $this->remit(45100)->assertSessionHasNoErrors();

        $this->actingAs($this->admin(), 'admin')->get(route('admin.logistics-remittances.index'))
            ->assertOk()->assertSee("Returned, the buyer's refund comes from this", false);
    }

    public function test_a_reference_is_required(): void
    {
        $this->parcel(50000, 4900);

        $this->remit(45100, '')->assertSessionHasErrors('reference');
    }

    public function test_admin_confirms_the_exact_amount_and_the_parcels_are_marked_received(): void
    {
        $parcel = $this->parcel(50000, 4900);
        $this->remit(45100);
        $payment = LogisticsRemittance::query()->sole();
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')->get(route('admin.logistics-remittances.index'))
            ->assertOk()->assertSee('Ninja Express')->assertSee('GCASH-1001')->assertSee('₱451.00');

        $this->post(route('admin.logistics-remittances.confirm', $payment), ['received_amount' => '450'])
            ->assertSessionHasErrors('received_amount');
        $this->assertNull($parcel->fresh()->zefanya_received_at);

        $this->post(route('admin.logistics-remittances.confirm', $payment), ['received_amount' => '451.00'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $payment->refresh();
        $this->assertSame(LogisticsRemittance::CONFIRMED, $payment->status);
        $this->assertSame(45100, $payment->received_amount_minor);
        $this->assertSame($admin->id, (int) $payment->decided_by_admin_id);
        $this->assertNotNull($parcel->fresh()->zefanya_received_at);

        $this->post(route('admin.logistics-remittances.confirm', $payment), ['received_amount' => '451.00'])
            ->assertSessionHas('success', 'This payment was already confirmed.');
    }

    public function test_a_short_payment_is_refused_and_can_be_rejected(): void
    {
        $parcel = $this->parcel(50000, 4900);
        $this->remit(45100);
        $payment = LogisticsRemittance::query()->sole();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.logistics-remittances.confirm', $payment), ['received_amount' => '400.00'])
            ->assertSessionHasErrors('received_amount');
        $this->assertSame(LogisticsRemittance::SUBMITTED, $payment->fresh()->status);

        $this->post(route('admin.logistics-remittances.reject', $payment), [])->assertSessionHasErrors('reason');
        $this->post(route('admin.logistics-remittances.reject', $payment), ['reason' => 'Only ₱400.00 arrived'])
            ->assertSessionHas('success');

        $this->assertSame(LogisticsRemittance::REJECTED, $payment->fresh()->status);
        $this->assertNull($parcel->fresh()->logistics_remittance_id);
        $this->assertNull($parcel->fresh()->zefanya_received_at);

        // The company sees why, and the same reference can be sent again.
        $this->actingAs($this->provider, 'logistics')->get(route('logistics.earnings.index'))
            ->assertOk()->assertSee('Only ₱400.00 arrived')->assertSee('">₱451.00</span> to Zefanya', false);
        $this->remit(45100)->assertSessionHasNoErrors();
    }

    public function test_only_full_admins_can_confirm_and_logistics_cannot(): void
    {
        $parcel = $this->parcel(50000, 4900);
        $this->remit(45100);
        $payment = LogisticsRemittance::query()->sole();

        $this->actingAs($this->admin('moderator'), 'admin')
            ->post(route('admin.logistics-remittances.confirm', $payment), ['received_amount' => '451.00'])
            ->assertForbidden();
        $this->get(route('admin.logistics-remittances.index'))->assertForbidden();

        auth('admin')->logout();
        $this->actingAs($this->provider, 'logistics')
            ->post(route('admin.logistics-remittances.confirm', $payment), ['received_amount' => '451.00'])
            ->assertRedirect();

        $this->assertNull($parcel->fresh()->zefanya_received_at);
    }

    public function test_every_step_is_in_the_audit_log(): void
    {
        $this->parcel(50000, 4900);
        $this->remit(45100);
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.logistics-remittances.confirm', LogisticsRemittance::query()->sole()), ['received_amount' => '451.00']);

        $submitted = OperationsAuditLog::query()->where('action', 'logistics_remittance_submitted')->sole();
        $this->assertSame('Ninja Express', $submitted->actor_name);
        $this->assertSame('₱451.00', $submitted->new_values['amount']);

        $confirmed = OperationsAuditLog::query()->where('action', 'logistics_remittance_confirmed')->sole();
        $this->assertSame('admin', $confirmed->actor_type);
        $this->assertSame((int) $this->provider->id, (int) $confirmed->logistics_provider_id);
        $this->assertSame('₱451.00', $confirmed->new_values['received']);
    }

    public function test_the_dashboard_owes_less_once_a_payment_is_confirmed(): void
    {
        $this->parcel(50000, 4900);
        $this->remit(45100);

        $admin = $this->admin();
        $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))
            ->assertViewHas('settlements', fn ($rows) => $rows->first()['owedMinor'] === 45100 && $rows->first()['receivedMinor'] === 0);

        $this->post(route('admin.logistics-remittances.confirm', LogisticsRemittance::query()->sole()), ['received_amount' => '451.00']);

        $this->get(route('admin.dashboard'))
            ->assertViewHas('settlements', fn ($rows) => $rows->first()['owedMinor'] === 0 && $rows->first()['receivedMinor'] === 45100);
    }

    public function test_admin_can_confirm_only_the_parcels_the_money_covers_and_the_rest_become_due_again(): void
    {
        Notification::fake();
        $first = $this->parcel(50000, 4900);
        $second = $this->parcel(30000, 2000);
        $this->remit(73100);
        $payment = LogisticsRemittance::query()->sole();
        $this->actingAs($this->admin(), 'admin');

        // The chosen parcels must add up to the money that arrived.
        $this->post(route('admin.logistics-remittances.confirm', $payment), ['received_amount' => '451.00', 'parcels' => [$first->id, $second->id]])
            ->assertSessionHasErrors('received_amount');
        $this->post(route('admin.logistics-remittances.confirm', $payment), ['received_amount' => '451.00', 'parcels' => [$first->id, 999999]])
            ->assertSessionHasErrors('parcels');
        $this->post(route('admin.logistics-remittances.confirm', $payment), ['received_amount' => '0', 'parcels' => []])
            ->assertSessionHasErrors('parcels');
        $this->assertSame(LogisticsRemittance::SUBMITTED, $payment->fresh()->status);

        $this->post(route('admin.logistics-remittances.confirm', $payment), ['received_amount' => '451.00', 'parcels' => [$first->id]])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $payment->refresh();
        $this->assertSame(LogisticsRemittance::CONFIRMED, $payment->status);
        $this->assertSame(45100, $payment->received_amount_minor);
        $this->assertSame(28000, $payment->short_minor);
        $this->assertSame(1, $payment->left_out_count);
        $this->assertTrue($payment->isPartlyConfirmed());
        $this->assertNotNull($first->fresh()->zefanya_received_at);
        $this->assertSame($payment->id, (int) $first->fresh()->logistics_remittance_id);
        $this->assertNull($second->fresh()->zefanya_received_at);
        $this->assertNull($second->fresh()->logistics_remittance_id);
        $this->assertSame(1, app(LogisticsRemittanceService::class)->readyParcels($this->provider)->count());

        $this->get(route('admin.logistics-remittances.index'))->assertSee('Partly confirmed')->assertSee('1 parcel due again');
        $this->assertTrue(OperationsAuditLog::query()->where('action', 'logistics_remittance_confirmed')->get()
            ->contains(fn ($log) => str_contains(json_encode($log->new_values), 'parcels_due_again')));
        Notification::assertSentTo($this->provider, LogisticsPaymentDecided::class,
            fn (LogisticsPaymentDecided $notification): bool => $notification->remittance->isPartlyConfirmed());

        // The left-out parcel can go into the next payment.
        $this->remit(28000, 'GCASH-2002')->assertSessionHasNoErrors();
    }

    public function test_overdue_cash_is_flagged_for_admin_and_for_the_company(): void
    {
        $sent = $this->parcel(50000, 4900);
        $sent->forceFill(['confirmed_at' => now()->subDays(5)])->save();
        $recent = $this->parcel(30000, 2000);
        $recent->forceFill(['confirmed_at' => now()->subDay()])->save();
        $withRider = $this->parcel(20000, 1000, CodRemittance::PENDING_REMITTANCE);
        $withRider->forceFill(['created_at' => now()->subDays(6)])->save();

        $row = app(LogisticsRemittanceService::class)->unsentByProvider()->sole();
        $this->assertSame(2, $row['parcels']);
        $this->assertSame(1, $row['overdueParcels']);
        $this->assertNotNull($row['oldestAt']);

        $this->actingAs($this->admin(), 'admin')->get(route('admin.logistics-remittances.index'))
            ->assertOk()->assertSee('1 overdue (over 3 days)');

        $this->actingAs($this->provider, 'logistics')->get(route('logistics.earnings.index'))
            ->assertOk()->assertSee('1 parcel has been waiting more than 3 days');
        $this->get(route('logistics.cod-remittances.index'))
            ->assertOk()->assertSee('1 remittance has been waiting for more than 3 days')->assertSee('Overdue');
    }

    private function legacyParcel(LogisticsProvider $carrier): CodRemittance
    {
        $parcel = $this->parcel(50000, 4900, carrier: $carrier);
        $payment = new LogisticsRemittance;
        $payment->forceFill([
            'logistics_provider_id' => $this->provider->id, 'parcel_count' => 1, 'cod_total_minor' => 50000,
            'shipping_total_minor' => 0, 'amount_minor' => 50000, 'reference' => 'OLD-'.$parcel->id, 'status' => LogisticsRemittance::CONFIRMED, 'confirmed_at' => now(),
        ])->save();
        $parcel->forceFill(['logistics_remittance_id' => $payment->id, 'zefanya_received_at' => now(), 'kept_shipping_minor' => 0, 'origin_share_minor' => 0])->save();

        return $parcel;
    }

    public function test_admin_reconciles_an_old_fee_once_and_the_origin_share_joins_the_normal_payout(): void
    {
        $carrier = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now(), 'business_name' => 'Origin Express']);
        $allocated = $this->legacyParcel($carrier);
        $kept = $this->legacyParcel($carrier);
        $modern = $this->parcel(50000, 4900, carrier: $carrier);
        $modern->snapshotAllocation();
        $modern->forceFill(['zefanya_received_at' => now()])->save();
        $service = app(LogisticsRemittanceService::class);

        $this->assertEqualsCanonicalizing([$allocated->id, $kept->id], $service->historicalToReconcile()->modelKeys());
        $this->assertSame(0, $service->originOwedByProvider()->where('providerId', $carrier->id)->sum('amountMinor') - 1960, 'only the modern parcel is owed so far');

        $this->actingAs($this->admin('moderator'), 'admin')
            ->post(route('admin.logistics-remittances.reconcile-historical', $allocated), ['decision' => 'allocated'])->assertForbidden();

        $this->actingAs($this->admin(), 'admin')->get(route('admin.logistics-remittances.index'))
            ->assertOk()->assertSee('Old shipping fees to reconcile')->assertSee('Split 40/60');

        $this->post(route('admin.logistics-remittances.reconcile-historical', $allocated), ['decision' => 'maybe'])->assertSessionHasErrors('decision');
        $this->post(route('admin.logistics-remittances.reconcile-historical', $allocated), ['decision' => 'allocated'])->assertSessionHasNoErrors();
        $this->post(route('admin.logistics-remittances.reconcile-historical', $kept), ['decision' => 'kept'])->assertSessionHasNoErrors();

        $allocated->refresh();
        $this->assertSame('allocated', $allocated->reconciliation);
        $this->assertSame(1960, $allocated->origin_share_minor);
        $this->assertSame(2940, $allocated->destination_share_minor);
        $this->assertSame(0, $allocated->kept_shipping_minor, 'the company already sent the whole fee');
        $this->assertSame(0, $kept->fresh()->origin_share_minor);
        $this->assertSame('kept', $kept->fresh()->reconciliation);
        $this->assertSame([], $service->historicalToReconcile()->modelKeys());

        // A decision is final.
        $this->post(route('admin.logistics-remittances.reconcile-historical', $allocated), ['decision' => 'kept'])->assertSessionHasErrors('parcel');
        $this->assertSame('allocated', $allocated->fresh()->reconciliation);

        // The origin 40% now appears with the modern parcel's share, and the delivering company's 60% is owed.
        $this->assertSame(3920, (int) $service->originOwedByProvider()->firstWhere('providerId', $carrier->id)['amountMinor']);
        $this->assertSame([$allocated->id], $service->destinationOwed()->modelKeys());

        $this->post(route('admin.logistics-remittances.pay-destination', $allocated), ['reference' => ''])->assertSessionHasErrors('reference');
        $this->post(route('admin.logistics-remittances.pay-destination', $allocated), ['reference' => 'DEST-1', 'paid_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame('DEST-1', $allocated->fresh()->destination_paid_reference);
        $this->assertSame([], $service->destinationOwed()->modelKeys());
        $this->post(route('admin.logistics-remittances.pay-destination', $allocated), ['reference' => 'DEST-2', 'paid_on' => now()->toDateString()])->assertSessionHasErrors('parcel');

        $this->assertDatabaseHas('operations_audit_logs', ['action' => 'historical_fee_reconciled']);
        $this->assertDatabaseHas('operations_audit_logs', ['action' => 'historical_destination_share_paid']);
    }

    private function payableOriginParcel(): array
    {
        $origin = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $parcel = $this->parcel(50000, 4900, carrier: $origin);
        $parcel->snapshotAllocation();
        $parcel->forceFill(['zefanya_received_at' => now()])->save();

        return [$origin, $parcel];
    }

    private function reverseOrigin(OriginSharePayout $payout, array $data = [], ?Admin $admin = null)
    {
        return $this->actingAs($admin ?? $this->admin(), 'admin')->from(route('admin.logistics-remittances.index'))
            ->post(route('admin.logistics-remittances.reverse-origin-payment', $payout), $data + [
                'reason' => 'Wrong payment date was entered.', 'confirm_reversal' => '1',
            ]);
    }

    public function test_origin_payment_notifies_only_its_company_and_is_visible_in_the_bell(): void
    {
        [$origin, $parcel] = $this->payableOriginParcel();
        $this->payOrigin($origin, $parcel->id, 1960)->assertSessionHasNoErrors();
        $payout = OriginSharePayout::query()->sole();
        $notice = $origin->notifications()->where('type', OriginSharePaymentRecorded::class)->sole();
        $this->assertSame($payout->id, $notice->data['payout_id']);
        $this->assertSame(1960, $notice->data['amount_minor']);
        $this->assertSame('BDO-500', $notice->data['reference']);
        $this->assertSame(now()->toDateString(), $notice->data['paid_on']);
        $this->assertSame(0, $this->provider->notifications()->where('type', OriginSharePaymentRecorded::class)->count());
        $this->actingAs($origin, 'logistics')->getJson(route('logistics.notifications.recent'))
            ->assertOk()->assertJsonPath('notifications.0.url', route('logistics.earnings.index'));
        $this->payOrigin($origin, $parcel->id, 1960)->assertSessionHasErrors('reference');
        $this->assertSame(1, $origin->notifications()->where('type', OriginSharePaymentRecorded::class)->count());
    }

    public function test_origin_reversal_preserves_payment_and_allocations_in_audit_then_allows_same_reference_replacement(): void
    {
        [$origin, $parcel] = $this->payableOriginParcel();
        $this->payOrigin($origin, $parcel->id, 1960)->assertSessionHasNoErrors();
        $original = OriginSharePayout::query()->sole();
        $attributes = $original->getAttributes();
        $this->reverseOrigin($original)->assertSessionHasNoErrors();
        $this->assertSame($attributes, $original->fresh()->getAttributes());
        $this->assertNull($parcel->fresh()->origin_share_payout_id);
        $this->assertNotNull($parcel->fresh()->zefanya_received_at);
        $log = $original->fresh()->reversal;
        $this->assertSame('admin', $log->actor_type);
        $this->assertSame('Wrong payment date was entered.', $log->reason);
        $this->assertSame((string) $parcel->id, $log->old_values['parcel_ids']);
        $this->assertSame('BDO-500', $log->old_values['reference']);
        $this->assertSame(0, app(LogisticsRemittanceService::class)->originShares($origin)['paidMinor']);
        $this->assertSame(1960, app(LogisticsRemittanceService::class)->originOwedByProvider()->sole()['amountMinor']);
        $this->actingAs($this->admin(), 'admin')->get(route('admin.logistics-remittances.index'))
            ->assertOk()->assertSee('Reversed')->assertSee($log->reason)->assertSee('BDO-500');
        $this->assertSame(2, $origin->notifications()->where('type', OriginSharePaymentRecorded::class)->count());

        // A correction keeps the actual bank reference; only an active duplicate is prohibited.
        $this->payOrigin($origin, $parcel->id, 1960)->assertSessionHasNoErrors();
        $this->assertSame(2, OriginSharePayout::query()->count());
        $this->assertNotSame($original->id, $parcel->fresh()->origin_share_payout_id);
        $this->assertSame(1960, app(LogisticsRemittanceService::class)->originShares($origin)['paidMinor']);
        $this->reverseOrigin($original)->assertSessionHasErrors('payout');
        $this->assertSame(1, OperationsAuditLog::query()->where('action', 'origin_share_reversed')->count());
        $this->assertSame($attributes, $original->fresh()->getAttributes());
    }

    public function test_origin_correction_requires_full_admin_reason_and_confirmation(): void
    {
        [$origin, $parcel] = $this->payableOriginParcel();
        $this->payOrigin($origin, $parcel->id, 1960)->assertSessionHasNoErrors();
        $payout = OriginSharePayout::query()->sole();
        foreach (['', 'short', str_repeat('x', 501)] as $reason) {
            $this->reverseOrigin($payout, ['reason' => $reason])->assertSessionHasErrors('reason');
        }
        $this->reverseOrigin($payout, ['confirm_reversal' => '0'])->assertSessionHasErrors('confirm_reversal');
        $this->reverseOrigin($payout, admin: $this->admin('moderator'))->assertForbidden();
        $this->assertNull($payout->fresh()->reversal);
        $this->assertSame($payout->id, $parcel->fresh()->origin_share_payout_id);
    }

    public function test_origin_reversal_failure_and_rollback_leave_original_allocations_intact(): void
    {
        [$origin, $parcel] = $this->payableOriginParcel();
        $this->payOrigin($origin, $parcel->id, 1960)->assertSessionHasNoErrors();
        $payout = OriginSharePayout::query()->sole();
        Notification::fake();
        try {
            DB::transaction(function () use ($payout): void {
                app(LogisticsRemittanceService::class)->reverseOriginPayout($this->admin(), $payout, 'Wrong payment date was entered.');
                throw new \RuntimeException('Rollback origin correction');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback origin correction', $exception->getMessage());
        }
        $this->assertNull($payout->fresh()->reversal);
        $this->assertSame($payout->id, $parcel->fresh()->origin_share_payout_id);
        Notification::assertNothingSent();

        $parcel->forceFill(['origin_share_minor' => 100])->save();
        $this->reverseOrigin($payout)->assertSessionHasErrors('payout');
        $this->assertNull($payout->fresh()->reversal);
        $this->assertSame($payout->id, $parcel->fresh()->origin_share_payout_id);
    }

    public function test_rolled_back_origin_payment_never_notifies_or_marks_the_share_paid(): void
    {
        [$origin, $parcel] = $this->payableOriginParcel();
        Notification::fake();
        try {
            DB::transaction(function () use ($origin, $parcel): void {
                app(LogisticsRemittanceService::class)->payOriginShare($this->admin(), $origin, $parcel->id, 1960, 'ROLLBACK', now()->toDateString());
                Notification::assertNothingSent();
                throw new \RuntimeException('Rollback origin payment');
            });
        } catch (\RuntimeException $exception) {
            $this->assertSame('Rollback origin payment', $exception->getMessage());
        }
        $this->assertSame(0, OriginSharePayout::query()->count());
        $this->assertNull($parcel->fresh()->origin_share_payout_id);
        Notification::assertNothingSent();
    }

    public function test_a_replacement_origin_reference_cannot_be_reused_for_another_active_payment(): void
    {
        [$origin, $parcel] = $this->payableOriginParcel();
        $this->payOrigin($origin, $parcel->id, 1960)->assertSessionHasNoErrors();
        $this->reverseOrigin(OriginSharePayout::query()->sole())->assertSessionHasNoErrors();
        $this->payOrigin($origin, $parcel->id, 1960)->assertSessionHasNoErrors();
        $later = $this->parcel(50000, 4900, carrier: $origin);
        $later->snapshotAllocation();
        $later->forceFill(['zefanya_received_at' => now()])->save();
        $this->payOrigin($origin, $later->id, 1960)->assertSessionHasErrors('reference');
        $this->assertNull($later->fresh()->origin_share_payout_id);
        $this->assertSame(2, OriginSharePayout::query()->count());
    }

    public function test_guests_and_logistics_cannot_reverse_admin_origin_payment_records(): void
    {
        [$origin, $parcel] = $this->payableOriginParcel();
        app(LogisticsRemittanceService::class)->payOriginShare($this->admin(), $origin, $parcel->id, 1960, 'AUTH-CHECK', now()->toDateString());
        $payout = OriginSharePayout::query()->sole();
        $url = route('admin.logistics-remittances.reverse-origin-payment', $payout);
        $this->post($url)->assertRedirect();
        $this->actingAs($origin, 'logistics')->post($url)->assertRedirect();
        $this->assertNull($payout->fresh()->reversal);
        $this->assertSame($payout->id, $parcel->fresh()->origin_share_payout_id);
    }

    public function test_older_origin_payments_remain_reachable_for_correction_from_their_own_history(): void
    {
        $admin = $this->admin();
        for ($index = 1; $index <= 21; $index++) {
            OriginSharePayout::query()->forceCreate([
                'logistics_provider_id' => $this->provider->id, 'parcel_count' => 1, 'amount_minor' => 1960,
                'reference' => 'HISTORY-'.$index, 'paid_on' => now()->toDateString(),
                'status' => OriginSharePayout::PAID, 'paid_by_admin_id' => $admin->id,
            ]);
        }
        $page = $this->actingAs($admin, 'admin')->get(route('admin.logistics-remittances.index'))->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($page->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertGreaterThan(0, $xpath->query('//section[@aria-labelledby="origin-heading"]//a[contains(@href,"origin_payouts_page=2")]')->length);
        $this->get(route('admin.logistics-remittances.index', ['origin_payouts_page' => 2]))
            ->assertOk()->assertSee('HISTORY-1')->assertSee('Correct payment record');
    }

    private function riderPay(LogisticsProvider $provider, string $job, int $orderId, ?int $amount = null): void
    {
        $courier = Courier::factory()->create(['logistics_provider_id' => $provider->id]);
        (new RiderEarning)->forceFill([
            'seller_order_id' => $orderId, 'job' => $job, 'courier_id' => $courier->id,
            'logistics_provider_id' => $provider->id, 'amount_minor' => $amount ?? RiderEarning::RATES[$job], 'earned_at' => now(),
        ])->save();
    }

    public function test_company_statement_deducts_rider_pay_from_earnings_but_not_from_the_cash_it_remits(): void
    {
        $origin = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $service = app(LogisticsFinanceService::class);

        // Same company picks up and delivers a ₱100 fee: 100 - 30 - 50 = 20.
        $same = $this->parcel(50000, 10000, carrier: $this->provider);
        $this->riderPay($this->provider, RiderEarning::PICKUP, $same->seller_order_id);
        $this->riderPay($this->provider, RiderEarning::DELIVERY, $same->seller_order_id);
        $statement = $service->statement($this->provider);
        $this->assertSame(10000, $statement['grossMinor']);
        $this->assertSame(8000, $statement['riderExpenseMinor']);
        $this->assertSame(2000, $statement['netMinor']);

        // Cross-provider ₱100 fee: the destination keeps 60 and pays its rider 50; the origin earns 40 and paid 30.
        $cross = $this->parcel(50000, 10000, carrier: $origin);
        $this->riderPay($origin, RiderEarning::PICKUP, $cross->seller_order_id);
        $this->riderPay($this->provider, RiderEarning::DELIVERY, $cross->seller_order_id);

        $destination = $service->statement($this->provider);
        $this->assertSame(16000, $destination['grossMinor']);
        $this->assertSame(0, $destination['originShareMinor']);
        $this->assertSame(13000, $destination['riderExpenseMinor']);
        $this->assertSame(3000, $destination['netMinor']);

        $originStatement = $service->statement($origin);
        $this->assertSame(4000, $originStatement['originShareMinor']);
        $this->assertSame(3000, $originStatement['riderPickupMinor']);
        $this->assertSame(1000, $originStatement['netMinor']);
        $this->assertSame(4000, $originStatement['originAwaitingZefanyaMinor']);
        $this->assertSame(3000, $originStatement['riderUnpaidMinor']);

        // Rider pay never changes what the destination must send Zefanya: cash - kept fee only.
        $this->assertSame(100000 - 16000, $this->parcelDue());

        $this->actingAs($this->provider, 'logistics')->get(route('logistics.earnings.index'))
            ->assertOk()->assertSee('Gross shipping earnings')->assertSee('Estimated net earnings')->assertSee('−₱100.00');
    }

    private function parcelDue(): int
    {
        return (int) app(LogisticsRemittanceService::class)->totals($this->provider)['ready']['dueMinor'];
    }

    public function test_platform_statement_keeps_pass_through_money_out_of_revenue(): void
    {
        $carrier = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $cross = $this->parcel(50000, 10000, carrier: $carrier);
        $this->parcel(30000, 5000);

        $platform = app(PlatformFinanceService::class);
        $position = $platform->position();
        $this->assertSame(0, $platform->earnings()['commissionMinor'], 'COD and shipping are not revenue');
        $this->assertSame(0, $position['originPayableMinor'], 'not owed until Zefanya has the cash');

        $this->remit(69000)->assertSessionHasNoErrors();
        $payment = LogisticsRemittance::query()->sole();
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.logistics-remittances.confirm', $payment), ['received_amount' => '690.00'])->assertSessionHasNoErrors();

        $position = $platform->position();
        $this->assertSame(4000, $position['originPayableMinor']);
        $this->assertSame(4000, $position['logisticsPayableMinor']);
        $this->assertSame(0, $position['outstandingCashMinor']);
        $this->assertSame(4000, $position['totalLiabilitiesMinor']);
        $this->assertSame(0, $platform->earnings()['netMinor']);
        $this->assertTrue($cross->fresh()->isCrossProvider());

        $this->actingAs($this->admin(), 'admin')->get(route('admin.dashboard'))
            ->assertOk()->assertSee('Liabilities and money in transit')->assertSee('Total owed by Zefanya');
    }
}
