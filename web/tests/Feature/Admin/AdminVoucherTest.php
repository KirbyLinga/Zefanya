<?php

namespace Tests\Feature\Admin;

use App\Enums\VoucherScope;
use App\Enums\VoucherType;
use App\Models\Admin\Admin;
use App\Models\Seller\Seller;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminVoucherTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-11-01 00:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'scope' => 'platform',
            'code' => 'PLAT100',
            'type' => 'percent',
            'percent' => 10,
            'max_discount' => '100.00',
            'starts_at' => '2026-11-02T10:00',
            'expires_at' => '2026-11-03T10:00',
            'availability' => 'code_only',
            'applies_to' => 'all',
            'is_active' => '1',
        ];
    }

    private function admin(string $role = 'admin'): Admin
    {
        return Admin::factory()->create(['role' => $role]);
    }

    public function test_admin_creates_platform_and_shipping_vouchers_funded_by_the_marketplace(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.vouchers.create'))
            ->assertOk()
            ->assertSee('Platform voucher, such as PLAT100', false)
            ->assertSee('Shipping voucher, such as FREESHIP', false)
            ->assertSee('The marketplace funds this discount')
            ->assertSee('All products');

        $this->post(route('admin.vouchers.store'), $this->payload())->assertRedirect(route('admin.vouchers.index'));
        $this->post(route('admin.vouchers.store'), $this->payload([
            'scope' => 'shipping',
            'code' => 'FREESHIP',
            'percent' => 99,
            'max_discount' => '250.00',
        ]))->assertRedirect(route('admin.vouchers.index'));

        $platform = Voucher::query()->where('code', 'PLAT100')->sole();
        $shipping = Voucher::query()->where('code', 'FREESHIP')->sole();

        $this->assertSame(VoucherScope::Platform, $platform->scope);
        $this->assertSame(VoucherScope::Shipping, $shipping->scope);
        $this->assertSame(Voucher::FUNDING_MARKETPLACE, $platform->funding_source);
        $this->assertSame(Voucher::FUNDING_MARKETPLACE, $shipping->funding_source);
        $this->assertNull($platform->seller_id);
        $this->assertNull($shipping->seller_id);
        $this->assertSame(10, $platform->value);
        $this->assertSame(10000, $platform->max_discount);
        $this->assertSame(99, $shipping->value);

        $shop = Voucher::factory()->shop(Seller::factory()->create())->create(['code' => 'SHOPONLY']);

        $this->get(route('admin.vouchers.index'))
            ->assertOk()
            ->assertSee('PLAT100')
            ->assertSee('FREESHIP')
            ->assertSee(route('admin.vouchers.index'), false)
            ->assertDontSee('SHOPONLY');

        $this->assertNotNull($shop->fresh());
    }

    public function test_a_shop_scope_is_rejected(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.vouchers.store'), $this->payload(['scope' => 'shop']))
            ->assertSessionHasErrors('scope');

        $this->assertSame(0, Voucher::query()->count());
    }

    public static function blockedRoles(): array
    {
        return [['moderator'], ['support']];
    }

    #[DataProvider('blockedRoles')]
    public function test_other_admin_roles_cannot_manage_vouchers(string $role): void
    {
        $this->actingAs($this->admin($role), 'admin');

        $this->get(route('admin.vouchers.index'))->assertForbidden();
        $this->post(route('admin.vouchers.store'), $this->payload())->assertForbidden();
        $this->get(route('admin.dashboard'))->assertDontSee(route('admin.vouchers.index'), false);
        $this->assertSame(0, Voucher::query()->count());
    }

    public function test_guests_are_redirected(): void
    {
        $this->get(route('admin.vouchers.index'))->assertRedirect(route('admin.login'));
        $this->post(route('admin.vouchers.store'), $this->payload())->assertRedirect(route('admin.login'));
    }

    public function test_a_shop_voucher_cannot_be_changed_from_the_admin_pages(): void
    {
        $shop = Voucher::factory()->shop(Seller::factory()->create())->create([
            'code' => 'SHOPONLY',
            'funding_source' => Voucher::FUNDING_SELLER,
        ]);

        $this->actingAs($this->admin(), 'admin');

        $this->get(route('admin.vouchers.edit', $shop))->assertForbidden();
        $this->put(route('admin.vouchers.update', $shop), $this->payload(['code' => 'TAKENOVER']))->assertForbidden();
        $this->delete(route('admin.vouchers.destroy', $shop))->assertForbidden();

        $this->assertSame('SHOPONLY', $shop->fresh()->code);
        $this->assertSame(Voucher::FUNDING_SELLER, $shop->fresh()->funding_source);
    }

    public function test_an_unused_voucher_can_be_edited_and_deleted(): void
    {
        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.vouchers.store'), $this->payload())
            ->assertRedirect();

        $voucher = Voucher::query()->where('code', 'PLAT100')->sole();

        $this->put(route('admin.vouchers.update', $voucher), $this->payload([
            'is_active' => '0',
            'usage_limit' => 25,
        ]))->assertRedirect(route('admin.vouchers.index'));

        $voucher->refresh();
        $this->assertFalse($voucher->is_active);
        $this->assertSame(25, $voucher->usage_limit);
        $this->assertSame(Voucher::FUNDING_MARKETPLACE, $voucher->funding_source);

        $this->delete(route('admin.vouchers.destroy', $voucher))->assertRedirect(route('admin.vouchers.index'));
        $this->assertNull($voucher->fresh());
    }

    public function test_a_used_voucher_keeps_its_terms_and_cannot_be_deleted(): void
    {
        $voucher = Voucher::factory()->platform()->create([
            'code' => 'PLAT100',
            'funding_source' => Voucher::FUNDING_MARKETPLACE,
            'type' => VoucherType::Percent,
            'value' => 10,
            'max_discount' => 10000,
            'times_used' => 3,
            'usage_limit' => 10,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
        ]);
        $shipment = SellerOrder::factory()->create();
        $shipment->forceFill(['platform_voucher_id' => $voucher->id])->save();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.vouchers.edit', $voucher))
            ->assertOk()
            ->assertDontSee('name="code"', false)
            ->assertSee('Platform voucher');

        $this->put(route('admin.vouchers.update', $voucher), $this->payload([
            'code' => 'HACKED',
            'scope' => 'shipping',
            'usage_limit' => 40,
            'expires_at' => '2026-12-01T10:00',
            'is_active' => '0',
        ]))->assertSessionHasNoErrors();

        $voucher->refresh();
        $this->assertSame('PLAT100', $voucher->code);
        $this->assertSame(VoucherScope::Platform, $voucher->scope);
        $this->assertSame(Voucher::FUNDING_MARKETPLACE, $voucher->funding_source);
        $this->assertSame(40, $voucher->usage_limit);
        $this->assertTrue($voucher->is_active);

        $this->delete(route('admin.vouchers.destroy', $voucher))->assertSessionHasErrors('voucher');
        $this->assertNotNull($voucher->fresh());
    }

    public function test_usage_limit_cannot_drop_below_what_was_already_used(): void
    {
        $voucher = Voucher::factory()->shipping()->create([
            'code' => 'FREESHIP',
            'funding_source' => Voucher::FUNDING_MARKETPLACE,
            'type' => VoucherType::Percent,
            'value' => 99,
            'max_discount' => 25000,
            'times_used' => 4,
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($this->admin(), 'admin')
            ->put(route('admin.vouchers.update', $voucher), $this->payload([
                'scope' => 'shipping',
                'code' => 'FREESHIP',
                'percent' => 99,
                'max_discount' => '250.00',
                'usage_limit' => 2,
                'starts_at' => $voucher->starts_at->timezone('Asia/Manila')->format('Y-m-d\TH:i'),
                'expires_at' => $voucher->expires_at->timezone('Asia/Manila')->format('Y-m-d\TH:i'),
            ]))->assertSessionHasErrors('usage_limit');

        $this->assertNull($voucher->fresh()->usage_limit);
        $this->assertSame(4, $voucher->fresh()->times_used);
    }
}
