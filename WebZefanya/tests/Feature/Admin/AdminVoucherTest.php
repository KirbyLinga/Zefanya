<?php

namespace Tests\Feature\Admin;

use App\Enums\VoucherScope;
use App\Enums\VoucherType;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
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

    public function test_admin_listing_and_create_use_the_shared_modal_with_catalog_thumbnails_and_defaults(): void
    {
        $product = Product::factory()->create(['name' => 'Full Catalog Scarf']);
        $product->images()->create(['path' => 'products/scarf.jpg', 'thumbnail_path' => 'products/scarf-thumb.jpg', 'is_primary' => true]);
        $this->actingAs($this->admin(), 'admin');

        $page = $this->get(route('admin.vouchers.index'))->assertOk()
            ->assertSee('id="create-voucher-dialog"', false)->assertSee('data-open="false"', false)
            ->assertSee('data-marketplace="true"', false)->assertSee('aria-haspopup="dialog"', false)
            ->assertSee('/storage/products/scarf-thumb.jpg', false)
            ->assertSee(route('admin.vouchers.code-availability'), false)
            ->assertSee('Marketplace voucher discovery and claiming are not currently available');
        $dom = new \DOMDocument;
        @$dom->loadHTML($page->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame('1', $xpath->evaluate('string(//input[@name="per_buyer_limit"]/@value)'));
        $this->assertSame('', $xpath->evaluate('string(//input[@name="usage_limit"]/@value)'));
        $this->assertSame('', $xpath->evaluate('string(//input[@name="min_spend"]/@value)'));
        $this->assertSame('code_only', $xpath->evaluate('string(//input[@name="availability"][@checked]/@value)'));
        $this->assertSame('platform', $xpath->evaluate('string(//input[@name="scope"][@checked]/@value)'));
        $this->get(route('admin.vouchers.create'))->assertOk()->assertSee('data-open="true"', false);
    }

    public function test_admin_creation_validation_reopens_the_shipping_draft_and_listing_errors_stay_separate(): void
    {
        $this->actingAs($this->admin(), 'admin')->from(route('admin.vouchers.index'))
            ->post(route('admin.vouchers.store'), $this->payload([
                '_modal' => 'admin-voucher-create', 'code' => 'RETRY99', 'scope' => 'shipping', 'percent' => 100, 'min_spend' => '123.45',
            ]))->assertRedirect(route('admin.vouchers.index'))->assertSessionHasErrors('percent');

        $this->withCookie(config('session.cookie'), session()->getId())->get(route('admin.vouchers.index'))
            ->assertOk()->assertSee('data-open="true"', false)->assertSee('value="RETRY99"', false)
            ->assertSee('value="123.45"', false)->assertSee('value="shipping" checked', false)
            ->assertSee('The percentage must be a whole number from 1 to 99.');

        $voucher = Voucher::factory()->platform()->create(['ended_at' => now()]);
        $this->get(route('admin.vouchers.edit', $voucher))->assertRedirect(route('admin.vouchers.index'))->assertSessionHasErrors('voucher');
        $this->withCookie(config('session.cookie'), session()->getId())->get(route('admin.vouchers.index'))
            ->assertOk()->assertSee('data-open="false"', false)->assertSee('This voucher has ended and can no longer be edited.');
    }

    public function test_admin_code_availability_is_normalized_global_and_validated(): void
    {
        Voucher::factory()->shop(Seller::factory()->create())->create(['code' => 'SHOPTAKEN']);
        Voucher::factory()->shipping()->create(['code' => 'SHIPTAKEN']);
        $this->actingAs($this->admin(), 'admin');

        $this->getJson(route('admin.vouchers.code-availability', ['code' => '  shoptaken  ']))
            ->assertOk()->assertExactJson(['available' => false])->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson(route('admin.vouchers.code-availability', ['code' => 'SHIPTAKEN']))
            ->assertOk()->assertExactJson(['available' => false]);
        $this->getJson(route('admin.vouchers.code-availability', ['code' => 'FRESH123']))
            ->assertOk()->assertExactJson(['available' => true]);
        $this->getJson(route('admin.vouchers.code-availability', ['code' => 'BAD-CODE']))
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->getJson(route('admin.vouchers.code-availability', ['code' => ['ARRAY']]))
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->getJson(route('admin.vouchers.code-availability'))->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    public function test_admin_code_availability_requires_admin_authentication_without_two_factor_enforcement(): void
    {
        $url = route('admin.vouchers.code-availability', ['code' => 'TEST123']);
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(Seller::factory()->create(['status' => 'approved']), 'seller')->getJson($url)->assertUnauthorized();
        $this->actingAs(Buyer::factory()->create(), 'buyer')->getJson($url)->assertUnauthorized();
        config(['admin.require_two_factor' => true]);
        $this->actingAs($this->admin(), 'admin')->getJson($url)->assertOk()->assertExactJson(['available' => true]);

        $enrolled = Admin::factory()->superAdmin()->create(['two_factor_secret' => 'TESTENROLLEDSECRET', 'two_factor_confirmed_at' => now()]);
        $this->actingAs($enrolled, 'admin')->getJson($url)->assertOk()->assertExactJson(['available' => true]);
    }

    public function test_admin_code_availability_is_rate_limited(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $url = route('admin.vouchers.code-availability', ['code' => 'TEST123']);

        for ($attempt = 0; $attempt < 30; $attempt++) {
            $this->getJson($url)->assertOk();
        }
        $this->getJson($url)->assertTooManyRequests();
    }

    public function test_admin_fixed_shipping_voucher_preserves_optional_blanks_and_inactive_choice(): void
    {
        $this->actingAs($this->admin(), 'admin')->post(route('admin.vouchers.store'), $this->payload([
            'scope' => 'shipping', 'type' => 'fixed', 'percent' => null, 'max_discount' => null,
            'fixed_amount' => '75.25', 'min_spend' => '', 'usage_limit' => '', 'per_buyer_limit' => '', 'is_active' => '0',
        ]))->assertSessionHasNoErrors()->assertRedirect(route('admin.vouchers.index'));

        $voucher = Voucher::sole();
        $this->assertSame(VoucherScope::Shipping, $voucher->scope);
        $this->assertSame(VoucherType::Fixed, $voucher->type);
        $this->assertSame(7525, $voucher->value);
        $this->assertSame(Voucher::FUNDING_MARKETPLACE, $voucher->funding_source);
        $this->assertNull($voucher->max_discount);
        $this->assertNull($voucher->min_spend);
        $this->assertNull($voucher->usage_limit);
        $this->assertNull($voucher->per_buyer_limit);
        $this->assertFalse($voucher->is_active);
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
        $this->getJson(route('admin.vouchers.code-availability', ['code' => 'TEST123']))->assertForbidden();
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

    public function test_edit_opens_over_the_directory_and_retains_a_rejected_draft(): void
    {
        $product = Product::factory()->create(['name' => 'Selected edit product']);
        $voucher = Voucher::factory()->shipping()->create([
            'code' => 'EDITSHIP', 'type' => VoucherType::Fixed, 'value' => 4999,
            'applies_to_all_products' => false, 'starts_at' => now()->addDay(), 'expires_at' => now()->addDays(5),
        ]);
        $voucher->products()->attach($product);
        $url = route('admin.vouchers.edit', $voucher);
        $page = $this->actingAs($this->admin(), 'admin')->get($url)->assertOk()
            ->assertSee('id="edit-voucher-dialog"', false)->assertSee('Voucher directory')
            ->assertSee('id="edit-voucher-fixed"', false)->assertSee('value="49.99"', false)
            ->assertSee('dialog.showModal()', false)->assertSee(route('admin.vouchers.update', $voucher), false);
        $dom = new \DOMDocument;
        @$dom->loadHTML($page->getContent());
        $xpath = new \DOMXPath($dom);
        $this->assertSame((string) $product->id, $xpath->evaluate('string(//form[@id="edit-voucher-form"]//input[@name="product_ids[]"][@checked]/@value)'));

        $this->from($url)->put(route('admin.vouchers.update', $voucher), $this->payload([
            '_modal' => 'admin-voucher-edit', 'code' => 'RETRYEDIT', 'percent' => 100,
        ]))->assertRedirect($url)->assertSessionHasErrors('percent');
        $this->withCookie(config('session.cookie'), session()->getId())->get($url)->assertOk()
            ->assertSee('value="RETRYEDIT"', false)->assertSee('id="edit-error-percent"', false)
            ->assertSee('aria-describedby="edit-error-percent"', false)
            ->assertSee('data-open="false"', false);
        $this->assertSame('EDITSHIP', $voucher->fresh()->code);
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

        $page = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.vouchers.edit', $voucher))
            ->assertOk()
            ->assertDontSee('id="edit-voucher-code"', false)
            ->assertSee('Platform voucher');
        $dom = new \DOMDocument;
        @$dom->loadHTML($page->getContent());
        $xpath = new \DOMXPath($dom);
        $editable = [];
        foreach ($xpath->query('//form[@id="edit-voucher-form"]//input[not(@type="hidden")]') as $input) {
            $editable[] = $input->getAttribute('name');
        }
        $this->assertSame(['usage_limit', 'per_buyer_limit', 'expires_at'], $editable);

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
