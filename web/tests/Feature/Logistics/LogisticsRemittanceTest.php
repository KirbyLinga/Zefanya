<?php

namespace Tests\Feature\Logistics;

use App\Enums\ParcelReturnStatus;
use App\Models\Admin\Admin;
use App\Models\Admin\OperationsAuditLog;
use App\Models\Admin\PlatformSetting;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsRemittance;
use App\Models\Logistics\ParcelReturn;
use App\Models\Rider\Courier;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Notifications\Admin\LogisticsPaymentRecorded;
use App\Notifications\Logistics\LogisticsPaymentDecided;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
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

    private function parcel(int $cod, int $shipping, string $status = CodRemittance::REMITTED_TO_LOGISTICS, ?LogisticsProvider $provider = null): CodRemittance
    {
        $order = SellerOrder::factory()->create(['shipping_fee_minor' => $shipping, 'tracking_number' => 'TRK-'.fake()->unique()->numerify('######')]);
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
}
