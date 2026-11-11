<?php

namespace Tests\Feature\Logistics;

use App\Models\Admin\OperationsAuditLog;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\CartItem;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsShippingRate;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use App\Services\Buyer\CheckoutCarrierService;
use App\Services\Logistics\ShippingRateService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BootstrapsCheckoutCarriers;
use Tests\TestCase;

class ShippingRateManagementTest extends TestCase
{
    use BootstrapsCheckoutCarriers;
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    private Seller $seller;

    private LogisticsProvider $carrier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->buyer = Buyer::factory()->create(['street' => 'Maple Street', 'municipality_name' => 'San Fernando']);
        $this->seller = Seller::factory()->create(['status' => 'approved']);
        $this->carrier = $this->bootstrapCheckoutCarriers($this->buyer, [$this->seller]);

        // One marketplace fee for every company and route: ₱50 for the first
        // 2 kg, then ₱20 per kilo. Saved company rate cards no longer price checkout.
        config([
            'buyer.shipping_fee' => ['base_minor' => 5000, 'base_kg' => 2, 'additional_kg_minor' => 2000],
            'buyer.cross_province.fee_minor' => null,
        ]);
    }

    private function save(array $rates, array $extra = []): TestResponse
    {
        return $this->actingAs($this->carrier, 'logistics')
            ->from(route('logistics.earnings.index', ['tab' => 'shipping-rates']))
            ->put(route('logistics.shipping-rates.update'), ['rates' => $rates] + $extra);
    }

    private function feeFor(int $grams): int
    {
        $options = app(CheckoutCarrierService::class)->optionsForSellerGroup($this->buyer->fresh(), $this->seller->fresh(), 10000, $grams);

        return $options['carriers'][0]['fee_minor'];
    }

    public function test_the_page_shows_the_one_fixed_rate_for_every_delivery(): void
    {
        $this->actingAs($this->carrier, 'logistics')
            ->get(route('logistics.shipping-rates.index'))
            ->assertRedirect(route('logistics.earnings.index', ['tab' => 'shipping-rates']));

        $this->followingRedirects()
            ->get(route('logistics.shipping-rates.index'))
            ->assertOk()
            ->assertSee('Overview', false)
            ->assertSee('Shipping Rates', false)
            ->assertSee('Manage Delivery Areas', false)
            ->assertDontSee('>Shipping rates<', false)
            ->assertSeeInOrder(['All deliveries', 'First 2 kg', '₱50.00', 'Each extra kg', '+ ₱20.00', '3 kg parcel', '₱70.00'], false);
    }

    public function test_a_carrier_with_no_rates_charges_the_fixed_fee(): void
    {
        $this->assertSame(5000, $this->feeFor(2000));
        $this->assertSame(5000 + 2000, $this->feeFor(2500));
    }

    public function test_a_saved_company_rate_does_not_change_the_checkout_fee(): void
    {
        $this->save(['same_municipality' => ['first_kg' => '60', 'additional_kg' => '12.50']])
            ->assertRedirect(route('logistics.earnings.index', ['tab' => 'shipping-rates']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(5000, $this->feeFor(1000));
        $this->assertSame(5000 + 2000, $this->feeFor(2500));

        // Other municipalities pay the same fee.
        $this->buyer->forceFill(['municipality_code' => '030540200'])->save();
        $this->assertSame(5000, $this->feeFor(1000));
    }

    public function test_order_value_does_not_waive_the_carrier_fee(): void
    {
        $this->save(['same_municipality' => ['first_kg' => '60', 'additional_kg' => '10']]);

        $options = app(CheckoutCarrierService::class)->optionsForSellerGroup($this->buyer->fresh(), $this->seller->fresh(), 150000, 1000);

        $this->assertSame(5000, $options['carriers'][0]['fee_minor']);
    }

    public function test_one_company_rates_do_not_touch_another(): void
    {
        $other = LogisticsProvider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->actingAs($other, 'logistics')
            ->put(route('logistics.shipping-rates.update'), ['rates' => ['same_municipality' => ['first_kg' => '999', 'additional_kg' => '99']]])
            ->assertSessionHasNoErrors();

        $this->assertSame(5000, $this->feeFor(1000));
    }

    public function test_clearing_a_saved_rate_keeps_the_fixed_fee(): void
    {
        $this->save(['same_municipality' => ['first_kg' => '60', 'additional_kg' => '10']]);
        $this->save(['same_municipality' => ['first_kg' => '', 'additional_kg' => '']])->assertSessionHasNoErrors();

        $this->assertSame(5000, $this->feeFor(1000));
    }

    /** @return array<string, array{0: array<string, string>, 1: string}> */
    public static function badRates(): array
    {
        return [
            'negative' => [['first_kg' => '-5', 'additional_kg' => '1'], 'first_kg'],
            'words' => [['first_kg' => 'fifty', 'additional_kg' => '1'], 'first_kg'],
            'three decimals' => [['first_kg' => '49.999', 'additional_kg' => '1'], 'first_kg'],
            'free extra kilo' => [['first_kg' => '49', 'additional_kg' => '0'], 'additional_kg'],
            'sub-cent extra kilo' => [['first_kg' => '49', 'additional_kg' => '0.001'], 'additional_kg'],
            'free first kilo' => [['first_kg' => '0', 'additional_kg' => '1'], 'first_kg'],
            'extra kilo missing' => [['first_kg' => '49', 'additional_kg' => ''], 'additional_kg'],
            'first kilo missing' => [['first_kg' => '', 'additional_kg' => '15'], 'first_kg'],
        ];
    }

    #[DataProvider('badRates')]
    public function test_bad_rates_are_refused_and_change_nothing(array $row, string $field): void
    {
        $this->save(['same_municipality' => $row])->assertSessionHasErrors("rates.same_municipality.{$field}");

        $this->assertSame(0, LogisticsShippingRate::query()->count());
        $this->assertSame(0, OperationsAuditLog::query()->count());
    }

    public function test_both_rates_accept_the_one_centavo_minimum(): void
    {
        $this->save(['same_municipality' => ['first_kg' => '0.01', 'additional_kg' => '0.01']])->assertSessionHasNoErrors();
        $this->assertSame(1, LogisticsShippingRate::query()->value('first_kg_minor'));
        $this->assertSame(5000, $this->feeFor(1000));
        $this->assertSame(5000 + 2 * 2000, $this->feeFor(4000));
    }

    public function test_a_rate_change_is_written_to_the_audit_log_once(): void
    {
        $rates = ['same_municipality' => ['first_kg' => '60', 'additional_kg' => '10']];
        $this->save($rates, ['reason' => 'Fuel prices went up.']);
        $this->save($rates, ['reason' => 'Fuel prices went up.'])->assertSessionHas('success', 'No rates were changed.');

        $log = OperationsAuditLog::query()->where('action', 'rate_changed')->sole();
        $this->assertSame('Same city or municipality', $log->entity_label);
        $this->assertSame('Marketplace rate', $log->old_values['first_kilo']);
        $this->assertSame('₱60.00', $log->new_values['first_kilo']);
        $this->assertSame('₱10.00', $log->new_values['each_extra_kilo']);
        $this->assertSame('Fuel prices went up.', $log->reason);

        $this->get(route('logistics.audit-logs.index'))->assertOk()->assertSee('Shipping rate changed');
    }

    public function test_a_cross_province_rate_saves_but_checkout_still_uses_the_fixed_fee(): void
    {
        $this->save(['cross_province' => ['first_kg' => '150', 'additional_kg' => '30']])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('logistics_shipping_rates', [
            'logistics_provider_id' => $this->carrier->id,
            'zone' => LogisticsShippingRate::CROSS_PROVINCE,
            'first_kg_minor' => 15000,
            'additional_kg_minor' => 3000,
        ]);
        $this->assertSame(
            ShippingRateService::fixedRate(),
            app(ShippingRateService::class)->rateFor($this->carrier, LogisticsShippingRate::CROSS_PROVINCE),
        );
    }

    public function test_only_checkouts_started_after_a_fee_change_use_the_new_fee(): void
    {
        $product = Product::factory()->create(['seller_id' => $this->seller->id, 'price' => 100, 'stock_quantity' => 50, 'status' => 'active', 'weight_grams' => 500]);
        $variant = ProductVariant::query()->where('product_id', $product->id)->firstOrFail();
        $variant->forceFill(['price' => 100, 'stock_quantity' => 50, 'is_available' => true, 'weight_grams' => null])->save();
        $line = CartItem::factory()->create(['buyer_id' => $this->buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]);
        $checkout = route('buyer.checkout.index', ['items' => [$line->id]]);

        $fieldsOf = function (TestResponse $page): array {
            $fields = [];
            foreach (['checkout_token', 'price_fingerprint', 'shipping_fingerprint', 'voucher_fingerprint'] as $name) {
                $this->assertSame(1, preg_match('/name="'.$name.'" value="([^"]*)"/', $page->getContent(), $match), $name);
                $fields[$name] = $match[1];
            }

            return $fields + $this->checkoutCarrierPayload([$this->seller]);
        };

        $fields = $fieldsOf($this->actingAs($this->buyer, 'buyer')->get($checkout)->assertOk());

        // The marketplace raises its fee while this checkout is open.
        config(['buyer.shipping_fee.base_minor' => 7500]);
        $this->actingAs($this->buyer, 'buyer')->post(route('buyer.checkout.store'), $fields)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(5000, SellerOrder::query()->sole()->shipping_fee_minor);

        $newLine = CartItem::factory()->create(['buyer_id' => $this->buyer->id, 'product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 1]);
        $newCheckout = route('buyer.checkout.index', ['items' => [$newLine->id]]);
        $newFields = $fieldsOf($this->get($newCheckout)->assertOk());
        $this->assertNotSame($fields['shipping_fingerprint'], $newFields['shipping_fingerprint']);
        $this->post(route('buyer.checkout.store'), $newFields)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame([5000, 7500], SellerOrder::orderBy('id')->pluck('shipping_fee_minor')->all());

        config(['buyer.shipping_fee.base_minor' => 20000]);
        $this->assertSame([5000, 7500], SellerOrder::orderBy('id')->pluck('shipping_fee_minor')->all());
    }

    public function test_rate_service_also_rejects_values_below_one_centavo_without_audit_changes(): void
    {
        foreach ([[0, 1], [1, 0], [-1, 1]] as [$first, $additional]) {
            try {
                app(ShippingRateService::class)->save($this->carrier, [
                    'same_municipality' => ['first_kg_minor' => $first, 'additional_kg_minor' => $additional],
                ]);
                $this->fail('Rate minimum must be enforced by the service.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertSame(0, LogisticsShippingRate::count());
        $this->assertSame(0, OperationsAuditLog::count());
    }

    public function test_audit_failure_rolls_back_the_rate_change(): void
    {
        $this->save(['same_municipality' => ['first_kg' => '60', 'additional_kg' => '10']])->assertSessionHasNoErrors();
        Event::listen('eloquent.creating: '.OperationsAuditLog::class, function (): void {
            throw new \RuntimeException('Audit write failed');
        });
        try {
            app(ShippingRateService::class)->save($this->carrier, [
                'same_municipality' => ['first_kg_minor' => 7500, 'additional_kg_minor' => 1000],
            ]);
            $this->fail('Rate changes require their audit entry.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit write failed', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.OperationsAuditLog::class);
        }
        $this->assertSame(6000, LogisticsShippingRate::query()->where('zone', LogisticsShippingRate::SAME_MUNICIPALITY)->value('first_kg_minor'));
        $this->assertSame(5000, $this->feeFor(1000));
        $this->assertSame(1, OperationsAuditLog::where('action', 'rate_changed')->count());
    }
}
