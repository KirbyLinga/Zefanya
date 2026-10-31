<?php

namespace Tests\Feature\Logistics;

use App\Enums\OrderStatus;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Courier;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Support\CreatesAssignableFleet;
use Tests\TestCase;

class CodReconciliationTest extends TestCase
{
    use CreatesAssignableFleet, LazilyRefreshDatabase;

    private LogisticsProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provider = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
    }

    private function ledger(Courier $courier, int $collected, ?int $received = null): CodRemittance
    {
        $parcel = SellerOrder::factory()->status(OrderStatus::Delivered)->create();
        $payment = Payment::factory()->paid()->create(['seller_order_id' => $parcel->id, 'amount_minor' => $collected]);
        $payment->forceFill(['collected_by_type' => 'rider', 'collected_by_id' => $courier->id, 'collected_amount_minor' => $collected, 'collected_at' => now()])->save();
        $remittance = new CodRemittance;
        $remittance->forceFill([
            'payment_id' => $payment->id, 'seller_order_id' => $parcel->id,
            'logistics_provider_id' => $courier->logistics_provider_id, 'courier_id' => $courier->id,
            'amount_minor' => $collected,
            'status' => $received === null ? CodRemittance::PENDING_REMITTANCE : CodRemittance::REMITTED_TO_LOGISTICS,
            'received_amount_minor' => $received,
            'confirmed_at' => $received === null ? null : now(),
        ])->save();

        return $remittance;
    }

    public function test_rider_totals_include_pending_and_confirmed_collections_and_highlight_shortfalls(): void
    {
        $short = $this->assignableCourier($this->provider);
        $balanced = $this->assignableCourier($this->provider);
        $this->ledger($short, 10000, 10000);
        $this->ledger($short, 5000);
        $this->ledger($short, 5000, 3000);
        $this->ledger($balanced, 7000, 7000);
        $other = LogisticsProvider::factory()->create();
        $foreign = $this->assignableCourier($other);
        $this->ledger($foreign, 999999);
        $response = $this->actingAs($this->provider, 'logistics')->get(route('logistics.cod-remittances.index'))
            ->assertOk()->assertSee('Reconciliation by rider')->assertSee('Shortfall')->assertSee('Balanced')
            ->assertSee("\u{20B1}70.00")->assertDontSee('data-cod-rider="'.$foreign->id.'"', false);
        $rows = $response->viewData('riderReconciliation');
        $this->assertSame(2, $rows->total());
        $this->assertSame($short->id, (int) $rows->first()->courier_id);
        $this->assertSame(20000, (int) $rows->first()->collected_minor);
        $this->assertSame(13000, (int) $rows->first()->remitted_minor);
        $this->assertSame(7000, (int) $rows->first()->difference_minor);
        $this->assertSame(0, (int) $rows->last()->difference_minor);
        $this->assertSame(27000, (int) $response->viewData('reconciliationTotals')->collected_minor);
        $this->assertSame(20000, (int) $response->viewData('reconciliationTotals')->remitted_minor);
    }

    public function test_totals_cover_all_pages_and_rider_and_parcel_pagination_are_independent(): void
    {
        for ($i = 1; $i <= 22; $i++) {
            $courier = Courier::factory()->create(['logistics_provider_id' => $this->provider->id]);
            $this->ledger($courier, $i * 100);
        }
        $first = $this->actingAs($this->provider, 'logistics')->get(route('logistics.cod-remittances.index'))->assertOk();
        $this->assertSame(22, $first->viewData('riderReconciliation')->total());
        $this->assertCount(20, $first->viewData('riderReconciliation'));
        $this->assertSame(25300, (int) $first->viewData('reconciliationTotals')->collected_minor);
        $this->assertSame(2200, (int) $first->viewData('riderReconciliation')->first()->difference_minor);
        $second = $this->get(route('logistics.cod-remittances.index', ['riders_page' => 2]))->assertOk();
        $this->assertCount(2, $second->viewData('riderReconciliation'));
        $this->assertSame(25300, (int) $second->viewData('reconciliationTotals')->collected_minor);
        $this->assertSame(1, $second->viewData('remittances')->currentPage());
    }

    public function test_confirming_cash_receipt_updates_reconciliation_without_double_counting(): void
    {
        $courier = $this->assignableCourier($this->provider);
        $remittance = $this->ledger($courier, 12100);
        $this->actingAs($this->provider, 'logistics')->post(route('logistics.cod-remittances.confirm', $remittance), ['received_amount' => '121.00'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('logistics.cod-remittances.confirm', $remittance), ['received_amount' => '121.00'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $response = $this->get(route('logistics.cod-remittances.index'))->assertOk()->assertSee('Balanced');
        $row = $response->viewData('riderReconciliation')->sole();
        $this->assertSame(12100, (int) $row->collected_minor);
        $this->assertSame(12100, (int) $row->remitted_minor);
        $this->assertSame(0, (int) $row->difference_minor);
        $this->assertSame(0, $response->viewData('remittances')->total());
    }

    public function test_pending_rows_do_not_count_as_remitted_and_centavos_are_exact(): void
    {
        $courier = $this->assignableCourier($this->provider);
        $pending = $this->ledger($courier, 1);
        $pending->forceFill(['received_amount_minor' => 1])->save();
        $this->ledger($courier, 2, 2);
        $response = $this->actingAs($this->provider, 'logistics')->get(route('logistics.cod-remittances.index'))->assertOk();
        $row = $response->viewData('riderReconciliation')->sole();
        $this->assertSame(3, (int) $row->collected_minor);
        $this->assertSame(2, (int) $row->remitted_minor);
        $this->assertSame(1, (int) $row->difference_minor);
        $response->assertSee("\u{20B1}0.03")->assertSee("\u{20B1}0.02")->assertSee("\u{20B1}0.01");
    }

    public function test_empty_state_and_authentication_are_preserved(): void
    {
        $this->get(route('logistics.cod-remittances.index'))->assertRedirect(route('home', ['login' => 1]));
        $response = $this->actingAs($this->provider, 'logistics')->get(route('logistics.cod-remittances.index'))
            ->assertOk()->assertSee('No COD collections to reconcile yet.');
        $this->assertSame(0, (int) $response->viewData('reconciliationTotals')->collected_minor);
        $this->assertSame(0, (int) $response->viewData('reconciliationTotals')->remitted_minor);
    }
}
