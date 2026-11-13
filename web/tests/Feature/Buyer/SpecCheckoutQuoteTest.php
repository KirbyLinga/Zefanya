<?php

namespace Tests\Feature\Buyer;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use App\Models\Catalog\Product;
use App\Models\Logistics\TransportConnection;
use App\Models\Seller\SellerShippingProvider;
use App\Models\Shared\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\BootstrapsCrossProviderHandoffs;
use Tests\TestCase;

class SpecCheckoutQuoteTest extends TestCase
{
    use BootstrapsCrossProviderHandoffs, LazilyRefreshDatabase;

    private function worked(): array
    {
        Http::preventStrayRequests();
        $this->seed(\Database\Seeders\SpecShippingRateSeeder::class);
        $ncr = $this->operationalHandoffProvider('NCR Carrier', '137404000', 'Metro Manila', '137404000', 'Quezon City', '1374040001', 'Diliman', '130000000', 'NCR');
        $cebu = $this->operationalHandoffProvider('Cebu Carrier', '072200000', 'Cebu', '072217000', 'Cebu City', '0722170001', 'Lahug', '070000000', 'Central Visayas');
        $this->activeHandoffLink($cebu['provider'], $ncr['provider'], Admin::factory()->create());
        TransportConnection::query()->create(['origin_hub_id' => $cebu['station']->id, 'destination_hub_id' => $ncr['station']->id, 'transport_mode' => TransportConnection::SEA, 'operating_provider_id' => $cebu['provider']->id, 'is_active' => true]);
        $seller1 = $this->sellerIn('137404000', 'Metro Manila', '137404000', 'Quezon City', '1374040001', 'Diliman');
        $seller2 = $this->sellerIn('072200000', 'Cebu', '072217000', 'Cebu City', '0722170001', 'Lahug');
        $buyer = $this->buyerIn('137404000', 'Metro Manila', '137404000', 'Quezon City', '1374040001', 'Diliman');
        $buyer->forceFill(['status' => 'approved', 'email_verified_at' => now()])->save();
        foreach ([[$seller1, $ncr], [$seller2, $cebu]] as [$seller, $carrier]) {
            SellerShippingProvider::query()->create(['seller_id' => $seller->id, 'logistics_provider_id' => $carrier['provider']->id, 'is_enabled' => true]);
        }
        $products = collect([
            Product::factory()->create(['seller_id' => $seller1->id, 'price' => '249.00', 'weight_grams' => 400, 'stock_quantity' => 20]),
            Product::factory()->create(['seller_id' => $seller1->id, 'price' => '450.00', 'weight_grams' => 800, 'stock_quantity' => 20]),
            Product::factory()->create(['seller_id' => $seller2->id, 'price' => '399.00', 'weight_grams' => 200, 'stock_quantity' => 20]),
        ]);
        Voucher::factory()->shop($seller1)->fixed(5000)->create(['code' => 'MCH50', 'min_spend' => 30000, 'funding_source' => Voucher::FUNDING_SELLER]);
        Voucher::factory()->platform()->fixed(10000)->create(['code' => 'PLAT100', 'min_spend' => 100000, 'funding_source' => Voucher::FUNDING_MARKETPLACE]);
        Voucher::factory()->shipping()->fixed(5000)->create(['code' => 'FREESHIP', 'min_spend' => 30000, 'funding_source' => Voucher::FUNDING_MARKETPLACE]);
        $payload = ['lines' => $products->map(fn (Product $product): array => ['product_id' => $product->id, 'quantity' => 1])->all(), 'voucher_codes' => ['mch50', 'plat100', 'freeship']];

        return compact('buyer', 'products', 'seller1', 'seller2', 'payload');
    }

    private function api(Buyer $buyer): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        return $this->withToken($buyer->createToken('quote-test', ['buyer'])->plainTextToken);
    }

    public function test_buy_chk_001_worked_quote_is_1083_with_6366_3634_split_and_no_business_writes(): void
    {
        ['buyer' => $buyer, 'payload' => $payload] = $this->worked();
        $this->api($buyer);
        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)/i', $query->sql) && ! str_contains($query->sql, 'personal_access_tokens')) {
                $writes[] = $query->sql;
            }
        });
        $checkout = app(\App\Services\Buyer\CheckoutService::class);
        $response = $this->postJson('/api/v1/buyer/checkout/quote', $payload)->assertOk()
            ->assertJsonPath('data.deliver_to.full', $checkout->addressSnapshot($buyer)['full'])
            ->assertJsonPath('data.address_fingerprint', $checkout->addressFingerprint($buyer))
            ->assertJsonPath('data.totals.merchandise_subtotal_minor', 109800)
            ->assertJsonPath('data.totals.shipping_total_minor', 18500)
            ->assertJsonPath('data.totals.merchandise_discount_minor', 15000)
            ->assertJsonPath('data.totals.shipping_discount_minor', 5000)
            ->assertJsonPath('data.totals.payable_total_minor', 108300);
        $this->assertSame([6366, 3634], array_column($response->json('data.stores'), 'platform_discount_minor'));
        $this->assertSame([], $writes, 'Authentication last-use tracking is housekeeping, not a checkout write.');
        foreach (['orders', 'cart_items', 'checkout_tokens', 'payments', 'inventory_transactions', 'voucher_claims'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, Voucher::query()->sum('times_used'));
    }

    public function test_quote_requires_auth_and_rejects_non_buyer_and_foreign_address(): void
    {
        ['buyer' => $buyer, 'payload' => $payload] = $this->worked();
        $this->postJson('/api/v1/buyer/checkout/quote', $payload)->assertUnauthorized();
        $rider = \App\Models\Rider\Rider::factory()->create(['status' => 'approved', 'email_verified_at' => now()]);
        $this->withToken($rider->createToken('rider', ['rider'])->plainTextToken)->postJson('/api/v1/buyer/checkout/quote', $payload)->assertForbidden();
        $address = BuyerAddress::query()->create(['buyer_id' => Buyer::factory()->create()->id, 'street' => 'Private address']);
        $this->api($buyer)->postJson('/api/v1/buyer/checkout/quote', [...$payload, 'address_id' => $address->id])->assertForbidden();
        $policy = new \App\Policies\CheckoutPolicy;
        $this->assertTrue($policy->quote($buyer));
        $this->assertFalse($policy->quote($buyer, $address));
    }

    public function test_buy_chk_004_unserviceable_address_is_422_and_creates_no_order(): void
    {
        ['buyer' => $buyer, 'payload' => $payload] = $this->worked();
        $address = BuyerAddress::query()->create(['buyer_id' => $buyer->id, 'province_code' => '020900000', 'province_name' => 'Batanes', 'municipality_code' => '020903000', 'municipality_name' => 'Itbayat', 'barangay_code' => '0209030001', 'street' => 'Raele']);
        $this->api($buyer)->postJson('/api/v1/buyer/checkout/quote', [...$payload, 'address_id' => $address->id])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_quote_validates_quantities_variants_codes_and_forged_fees(): void
    {
        ['buyer' => $buyer, 'payload' => $payload, 'products' => $products] = $this->worked();
        $this->api($buyer);
        foreach ([0, 100000] as $quantity) {
            $this->postJson('/api/v1/buyer/checkout/quote', ['lines' => [['product_id' => $products[0]->id, 'quantity' => $quantity]]])->assertUnprocessable();
        }
        $this->postJson('/api/v1/buyer/checkout/quote', [...$payload, 'shipping_fee' => 1])->assertUnprocessable();
        $this->postJson('/api/v1/buyer/checkout/quote', [...$payload, 'voucher_codes' => ['BAD-CODE']])->assertUnprocessable();
        $this->postJson('/api/v1/buyer/checkout/quote', ['lines' => [['product_id' => $products[0]->id, 'variant_id' => $products[1]->variants()->sole()->id, 'quantity' => 1]]])->assertUnprocessable();
    }

    public function test_buy_chk_011_prices_are_ignored_and_shop_voucher_is_replaced(): void
    {
        ['buyer' => $buyer, 'payload' => $payload, 'seller1' => $seller] = $this->worked();
        Voucher::factory()->shop($seller)->percent(10)->create(['code' => 'MCH10', 'min_spend' => 50000, 'max_discount' => 10000]);
        $payload['lines'][0]['unit_price'] = 1;
        $this->api($buyer)->postJson('/api/v1/buyer/checkout/quote', [...$payload, 'voucher_codes' => ['MCH50', 'PLAT100', 'FREESHIP', 'MCH10']])
            ->assertOk()->assertJsonPath('data.totals.payable_total_minor', 106310)
            ->assertJsonPath('data.vouchers_state.shop:'.$seller->id, 'MCH10');
    }

    public function test_seed_is_idempotent_and_chargeable_weight_rounds_up(): void
    {
        $this->seed(\Database\Seeders\SpecShippingRateSeeder::class);
        $this->seed(\Database\Seeders\SpecShippingRateSeeder::class);
        $this->assertDatabaseCount('shipping_rates', 4);
        $this->assertSame(1, \App\Services\Buyer\CheckoutCarrierService::chargeableKg(1000));
        $this->assertSame(2, \App\Services\Buyer\CheckoutCarrierService::chargeableKg(1200));
        $this->assertSame(['first_kg_minor' => 6000, 'additional_kg_minor' => 2000], \App\Models\Shared\ShippingRate::cards()['visayas:visayas']);
    }

    public function test_duplicate_lines_cannot_bypass_variant_stock_and_preview_is_throttled(): void
    {
        ['buyer' => $buyer, 'products' => $products] = $this->worked();
        $this->api($buyer)->postJson('/api/v1/buyer/checkout/quote', ['lines' => [
            ['product_id' => $products[0]->id, 'quantity' => 11],
            ['product_id' => $products[0]->id, 'quantity' => 10],
        ]])->assertUnprocessable()->assertJsonValidationErrors('lines');
        for ($attempt = 1; $attempt < 20; $attempt++) {
            $this->postJson('/api/v1/buyer/checkout/quote', ['lines' => []])->assertUnprocessable();
        }
        $this->postJson('/api/v1/buyer/checkout/quote', ['lines' => []])->assertStatus(429);
        $this->assertDatabaseCount('checkout_tokens', 0);
    }

    public function test_provider_override_precedes_reference_rates_and_snapshot_stays_stable(): void
    {
        ['buyer' => $buyer, 'products' => $products, 'payload' => $payload] = $this->worked();
        $provider = \App\Models\Logistics\LogisticsProvider::query()->find(
            SellerShippingProvider::query()->where('seller_id', $products[0]->seller_id)->value('logistics_provider_id'),
        );
        $this->assertNotNull($provider);
        $rates = app(\App\Services\Logistics\ShippingRateService::class);
        $zone = \App\Models\Logistics\LogisticsShippingRate::SAME_MUNICIPALITY;
        \App\Models\Logistics\LogisticsShippingRate::query()->create([
            'logistics_provider_id' => $provider->id, 'zone' => $zone,
            'first_kg_minor' => 9000, 'additional_kg_minor' => 2500,
        ]);
        $snapshot = $rates->snapshotForProviders(collect([$provider]));
        $this->assertSame(['first_kg_minor' => 9000, 'additional_kg_minor' => 2500], $rates->rateFor($provider, $zone, 'ncr:ncr'));
        \App\Models\Shared\ShippingRate::query()->where('origin_zone', 'ncr')->where('destination_zone', 'ncr')->update(['first_kg_minor' => 99000]);
        $this->assertSame(5000, $snapshot[$provider->id]['_spec_routes']['ncr:ncr']['first_kg_minor']);
        $this->assertContains($zone, $snapshot[$provider->id]['_overrides']);
        $this->api($buyer)->postJson('/api/v1/buyer/checkout/quote', $payload)
            ->assertOk()->assertJsonPath('data.totals.shipping_total_minor', 23500);
    }

    public function test_expected_total_conflict_rolls_back_then_matching_total_places_the_worked_order(): void
    {
        ['buyer' => $buyer, 'products' => $products] = $this->worked();
        $ids = $products->map(fn (Product $product): int => \App\Models\Buyer\CartItem::factory()->create([
            'buyer_id' => $buyer->id, 'product_id' => $product->id,
            'variant_id' => $product->variants()->sole()->id, 'quantity' => 1,
        ])->id)->all();
        $this->api($buyer);
        $review = $this->postJson('/api/buyer/checkout/quote', ['item_ids' => $ids, 'vouchers' => ['MCH50', 'PLAT100', 'FREESHIP']])
            ->assertOk()->assertJsonPath('data.totals.payable_total_minor', 108300)->json('data');
        $carriers = [];
        foreach ($review['shipping']['options'] as $option) {
            $carriers[$option['seller_id']] = $option['carriers'][0]['id'];
        }
        $payload = [
            'item_ids' => $ids, 'checkout_token' => $review['checkout_token'],
            'carriers' => $carriers, 'vouchers' => $review['vouchers_state'],
            'price_fingerprint' => $review['fingerprints']['price'],
            'address_fingerprint' => $review['fingerprints']['address'],
            'shipping_fingerprint' => $review['fingerprints']['shipping'],
            'voucher_fingerprint' => $review['fingerprints']['voucher'],
            'expected_total' => '1082.99',
        ];
        foreach (['1083.001', '-1', '1e3'] as $invalidTotal) {
            $this->postJson('/api/buyer/checkout', [...$payload, 'expected_total' => $invalidTotal])
                ->assertUnprocessable()->assertJsonValidationErrors('expected_total');
        }
        $this->travel(61)->seconds();
        $this->postJson('/api/buyer/checkout', $payload)->assertConflict();
        foreach (['orders', 'payments', 'inventory_transactions'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, Voucher::query()->sum('times_used'));
        $this->assertDatabaseHas('checkout_tokens', ['token' => $review['checkout_token'], 'used' => false]);
        foreach ($products as $product) {
            $this->assertSame(20, $product->variants()->sole()->stock_quantity);
        }
        \App\Models\Shared\ShippingRate::query()->where('origin_zone', 'ncr')->where('destination_zone', 'ncr')
            ->update(['first_kg_minor' => 6000]);
        $this->postJson('/api/buyer/checkout', [...$payload, 'expected_total' => '1083.00'])
            ->assertCreated()->assertJsonPath('data.total_minor', 108300);
        $this->assertSame([6366, 3634], \App\Models\Shared\SellerOrder::query()->orderBy('seller_id')->pluck('platform_voucher_discount_minor')->all());
        $this->assertSame(3, (int) Voucher::query()->sum('times_used'));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('checkout_tokens', ['token' => $review['checkout_token'], 'used' => true]);
    }
}
