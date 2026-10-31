<?php

namespace Tests\Feature\Seller;

use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreProfileTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('public');
        Http::preventStrayRequests();
        Cache::put('psgc:regions', [['code' => '030000000', 'name' => 'Central Luzon']]);
        Cache::put('psgc:provinces:030000000', [['code' => '030540000', 'name' => 'Pampanga']]);
        Cache::put('psgc:cities:030540000:030000000', [['code' => '030540100', 'name' => 'San Fernando']]);
        Cache::put('psgc:barangays:030540100', [['code' => '0305401001', 'name' => 'Barangay One']]);
    }

    public function test_profile_page_exposes_all_store_fields_and_existing_values(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved', 'business_name' => 'Existing Store']);

        $response = $this->actingAs($seller, 'seller')->get(route('seller.account.index'))
            ->assertOk()->assertSee('Store Profile')->assertSee('Existing Store')->assertSee($seller->email);

        foreach (['business_name', 'store_description', 'logo', 'contact_no', 'contact_email', 'region_code', 'province_code', 'municipality_code', 'barangay_code', 'street', 'house_number', 'postal_code', 'address_detail'] as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }
        $response->assertSee('multipart/form-data', false)->assertSee('window.zfAddressLookup', false);
    }

    public function test_seller_can_update_store_profile_and_canonical_address_without_changing_identity(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $other = Seller::factory()->create(['business_name' => 'Other Store']);
        $originalPassword = $seller->password;
        $data = $this->payload($seller) + [
            'province_name' => 'Forged Province', 'municipality_name' => 'Forged City', 'barangay_name' => 'Forged Barangay',
            'status' => 'suspended',
            'email' => 'forged@example.com', 'password' => 'ForgedPassword123!', 'store_logo_path' => 'forged.php',
        ];
        $data['contact_no'] = '+63 917 123 4567';
        $data['contact_email'] = 'PUBLIC@EXAMPLE.COM';
        $data['store_description'] = '<script>alert(1)</script>';

        $this->actingAs($seller, 'seller')->patch(route('seller.account.update'), $data)
            ->assertRedirect(route('seller.account.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('sellers', [
            'id' => $seller->id, 'business_name' => 'Updated Store', 'contact_no' => '09171234567',
            'contact_email' => 'public@example.com', 'region_code' => '030000000',
            'province_name' => 'Pampanga', 'municipality_name' => 'San Fernando', 'barangay_name' => 'Barangay One',
            'address_mode' => 'api', 'street' => 'New Street', 'house_number' => '12', 'postal_code' => '2000',
            'status' => 'approved', 'email' => $seller->email, 'password' => $originalPassword, 'store_logo_path' => null,
        ]);
        $this->assertSame('Other Store', $other->fresh()->business_name);
        $this->get(route('seller.account.index'))->assertOk()->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_logo_is_uploaded_replaced_and_removed_without_orphaning_old_uploads(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->actingAs($seller, 'seller');
        $this->patch(route('seller.account.update'), $this->payload($seller) + ['logo' => UploadedFile::fake()->image('logo.png')])
            ->assertSessionHasNoErrors()->assertRedirect();
        $first = $seller->fresh()->store_logo_path;
        Storage::disk('public')->assertExists($first);
        $this->assertStringStartsWith('seller-logos/'.$seller->id.'/', $first);
        $this->get(route('seller.account.index'))->assertSee('/storage/'.ltrim($first, '/'), false);

        $this->patch(route('seller.account.update'), $this->payload($seller))->assertSessionHasNoErrors();
        $this->assertSame($first, $seller->fresh()->store_logo_path);

        $this->patch(route('seller.account.update'), $this->payload($seller) + ['logo' => UploadedFile::fake()->image('replacement.png')])
            ->assertSessionHasNoErrors();
        $second = $seller->fresh()->store_logo_path;
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        $this->patch(route('seller.account.update'), $this->payload($seller) + ['remove_logo' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($seller->fresh()->store_logo_path);
        Storage::disk('public')->assertMissing($second);
    }

    public static function invalidFields(): array
    {
        return [
            ['business_name', ''], ['store_description', str_repeat('x', 501)],
            ['contact_no', 'not-a-phone'], ['contact_email', 'not-an-email'],
            ['region_code', ''], ['street', ''], ['postal_code', str_repeat('1', 11)],
        ];
    }

    #[DataProvider('invalidFields')]
    public function test_invalid_profile_fields_leave_the_store_unchanged(string $field, string $value): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $payload = $this->payload($seller);
        $payload[$field] = $value;

        $this->actingAs($seller, 'seller')->from(route('seller.account.index'))->patch(route('seller.account.update'), $payload)
            ->assertRedirect(route('seller.account.index'))->assertSessionHasErrors($field);

        $this->assertSame($seller->business_name, $seller->fresh()->business_name);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_duplicate_mobile_and_invalid_logo_files_are_rejected(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $other = Seller::factory()->create();
        $this->actingAs($seller, 'seller');
        $payload = $this->payload($seller);
        $payload['contact_no'] = $other->contact_no;
        $this->patch(route('seller.account.update'), $payload)->assertSessionHasErrors('contact_no');

        foreach ([UploadedFile::fake()->create('logo.svg', 1, 'image/svg+xml'), UploadedFile::fake()->create('fake.png', 1, 'image/png'), UploadedFile::fake()->image('large.png')->size(5223), UploadedFile::fake()->image('unsupported.webp')] as $logo) {
            $this->patch(route('seller.account.update'), $this->payload($seller) + ['logo' => $logo])->assertSessionHasErrors('logo');
        }
        $this->patch(route('seller.account.update'), $this->payload($seller) + ['logo' => UploadedFile::fake()->image('logo.png'), 'remove_logo' => '1'])
            ->assertSessionHasErrors('logo');
        $this->assertNull($seller->fresh()->store_logo_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_invalid_address_or_unavailable_lookup_cannot_save_profile_or_logo(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $payload = $this->payload($seller) + ['logo' => UploadedFile::fake()->image('logo.png')];
        $payload['barangay_code'] = 'wrong-barangay';
        $this->actingAs($seller, 'seller')->patch(route('seller.account.update'), $payload)->assertSessionHasErrors('barangay_code');

        Cache::forget('psgc:regions');
        Http::fake(['*/regions/' => Http::response([], 503)]);
        $this->patch(route('seller.account.update'), $this->payload($seller))->assertSessionHasErrors('location');
        $this->assertSame($seller->business_name, $seller->fresh()->business_name);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_guests_other_roles_and_suspended_sellers_cannot_edit_store_profiles(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $this->get(route('seller.account.index'))->assertRedirect(route('home', ['login' => 1]));
        $this->patch(route('seller.account.update'), $this->payload($seller))->assertRedirect(route('home', ['login' => 1]));
        $this->actingAs(Buyer::factory()->create(), 'buyer')->patch(route('seller.account.update'), $this->payload($seller))
            ->assertRedirect(route('home', ['login' => 1]));
        $seller->forceFill(['status' => 'suspended'])->save();
        $this->actingAs($seller, 'seller')->patch(route('seller.account.update'), $this->payload($seller))
            ->assertRedirect(route('home', ['login' => 1]));
        $this->assertGuest('seller');
        $this->assertSame($seller->business_name, $seller->fresh()->business_name);
    }

    public function test_failed_save_removes_new_upload_and_preserves_existing_logo_and_profile(): void
    {
        $seller = Seller::factory()->create(['status' => 'approved']);
        $oldLogo = 'seller-logos/'.$seller->id.'/original.png';
        Storage::disk('public')->put($oldLogo, 'original-logo');
        $seller->forceFill(['store_logo_path' => $oldLogo])->save();
        Exceptions::fake();
        DB::swap(\Mockery::mock(DB::getFacadeRoot())
            ->shouldReceive('transaction')->once()->andThrow(new \RuntimeException('Store save unavailable.'))->getMock());

        $this->actingAs($seller, 'seller')->patch(route('seller.account.update'), $this->payload($seller) + ['logo' => UploadedFile::fake()->image('new.png')])
            ->assertServerError();

        $this->assertDatabaseHas('sellers', ['id' => $seller->id, 'business_name' => $seller->business_name, 'store_logo_path' => $oldLogo]);
        $this->assertSame([$oldLogo], Storage::disk('public')->allFiles());
        Exceptions::assertReported(\RuntimeException::class);
    }

    private function payload(Seller $seller): array
    {
        return [
            'business_name' => 'Updated Store', 'store_description' => 'Independent local shop.',
            'contact_no' => $seller->contact_no, 'contact_email' => 'shop@example.com',
            'region_code' => '030000000', 'province_code' => '030540000',
            'municipality_code' => '030540100', 'barangay_code' => '0305401001',
            'street' => 'New Street', 'house_number' => '12', 'address_detail' => 'Near the plaza', 'postal_code' => '2000',
        ];
    }
}
