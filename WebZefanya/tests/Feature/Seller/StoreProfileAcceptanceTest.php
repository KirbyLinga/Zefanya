<?php

namespace Tests\Feature\Seller;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StoreProfileAcceptanceTest extends TestCase
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

    private function data(Seller $seller, array $changes = []): array
    {
        return array_replace([
            'business_name' => 'Maria Clara Handicrafts PH',
            'store_description' => 'Handwoven bags and home decor from Bulacan artisans.',
            'contact_no' => $seller->contact_no, 'contact_email' => 'store@example.com',
            'region_code' => '030000000', 'province_code' => '030540000',
            'municipality_code' => '030540100', 'barangay_code' => '0305401001',
            'street' => 'Preserved Street', 'house_number' => '12', 'address_detail' => 'Near the plaza', 'postal_code' => '2000',
        ], $changes);
    }

    public function test_sel_prof_001_saved_profile_is_visible_on_the_store_and_buyer_search(): void
    {
        $seller = Seller::factory()->create(['business_name' => 'Previous Store']);
        $other = Seller::factory()->create();
        $before = $other->fresh()->getRawOriginal();
        $data = $this->data($seller, ['contact_no' => '09189876543', 'logo' => UploadedFile::fake()->image('store_logo.png')->size(300), 'banner' => UploadedFile::fake()->image('store_banner.jpg')->size(1843)]);
        $this->actingAs($seller, 'seller')->patch(route('seller.account.update'), $data)->assertSessionHasNoErrors()->assertSessionHas('success');
        $saved = $seller->fresh();
        $this->assertSame('Maria Clara Handicrafts PH', $saved->business_name);
        $this->assertSame('09189876543', $saved->contact_no);
        $this->get(route('seller.account.index'))->assertSee($saved->business_name)->assertSee('name="banner"', false);
        $this->get(route('buyer.stores.show', $seller))->assertOk()->assertSee($saved->business_name)->assertSee($saved->store_description)
            ->assertSee('/storage/'.ltrim($saved->store_logo_path, '/'), false)
            ->assertSee('/storage/'.ltrim($saved->store_banner_path, '/'), false)
            ->assertDontSee('Previous Store');
        $this->get(route('buyer.search.index', ['q' => '  MARIA CLARA HANDICRAFTS ph ']))->assertOk()
            ->assertViewHas('stores', fn ($stores) => $stores->modelKeys() === [$seller->id])->assertSee(route('buyer.stores.show', $seller));
        $this->get(route('buyer.search.index', ['q' => 'Previous Store']))->assertViewHas('stores', fn ($stores) => $stores->isEmpty());
        $this->assertSame($before, $other->fresh()->getRawOriginal());
    }

    public static function missingFields(): array
    {
        return array_map(fn ($field) => [$field], ['business_name', 'contact_no', 'region_code', 'province_code', 'municipality_code', 'barangay_code', 'street']);
    }

    #[DataProvider('missingFields')]
    public function test_sel_prof_002_required_fields_cannot_be_cleared(string $field): void
    {
        $seller = Seller::factory()->create();
        $before = $seller->fresh()->getRawOriginal();
        $this->actingAs($seller, 'seller')->patchJson(route('seller.account.update'), $this->data($seller, [$field => '']))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $seller->fresh()->getRawOriginal());
    }

    public static function nameBoundaries(): array
    {
        return [['     ', false], ['MC', false], ['MCH', true], [str_repeat('a', 30), true], [str_repeat('a', 31), false], ['  Trimmed Name  ', true]];
    }

    #[DataProvider('nameBoundaries')]
    public function test_sel_prof_003_and_005_trimmed_name_boundaries(string $name, bool $valid): void
    {
        $seller = Seller::factory()->create();
        $response = $this->actingAs($seller, 'seller')->patch(route('seller.account.update'), $this->data($seller, ['business_name' => $name]));
        if ($valid) {
            $response->assertSessionHasNoErrors();
            $this->assertSame(trim($name), $seller->fresh()->business_name);
        } else {
            $response->assertSessionHasErrors('business_name');
            $this->assertSame($seller->business_name, $seller->fresh()->business_name);
        }
    }

    public static function duplicateNames(): array
    {
        return [['RV Gadget Hub'], ['rv gadget hub'], ['  RV Gadget Hub  ']];
    }

    #[DataProvider('duplicateNames')]
    public function test_sel_prof_004_rejects_case_and_space_variations_of_a_legacy_name(string $name): void
    {
        $owner = Seller::factory()->create(['business_name' => 'RV Gadget Hub']);
        $seller = Seller::factory()->create();
        $this->actingAs($seller, 'seller')->patch(route('seller.account.update'), $this->data($seller, ['business_name' => $name, 'logo' => UploadedFile::fake()->image('new.png')]))->assertSessionHasErrors('business_name');
        $this->assertSame($seller->business_name, $seller->fresh()->business_name);
        $this->assertSame('RV Gadget Hub', $owner->fresh()->business_name);
        $this->assertNull($owner->fresh()->store_name_key);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_claimed_names_are_unique_but_a_store_can_keep_its_own_name(): void
    {
        $one = Seller::factory()->create();
        $two = Seller::factory()->create();
        $this->actingAs($one, 'seller')->patch(route('seller.account.update'), $this->data($one))->assertSessionHasNoErrors();
        $this->patch(route('seller.account.update'), $this->data($one))->assertSessionHasNoErrors();
        $this->actingAs($two, 'seller')->patch(route('seller.account.update'), $this->data($two, ['business_name' => '  maria clara handicrafts PH  ']))->assertSessionHasErrors('business_name');
        $this->assertSame($two->business_name, $two->fresh()->business_name);
    }

    public static function descriptionBounds(): array
    {
        return [[500, true], [501, false]];
    }

    #[DataProvider('descriptionBounds')]
    public function test_sel_prof_006_does_not_truncate_descriptions(int $length, bool $valid): void
    {
        $seller = Seller::factory()->create();
        $text = str_repeat('x', $length);
        $response = $this->actingAs($seller, 'seller')->patch(route('seller.account.update'), $this->data($seller, ['store_description' => $text]));
        if ($valid) {
            $response->assertSessionHasNoErrors();
            $this->get(route('buyer.stores.show', $seller))->assertSee($text);
            $this->assertSame($text, $seller->fresh()->store_description);
        } else {
            $response->assertSessionHasErrors('store_description');
            $this->assertSame($seller->store_description, $seller->fresh()->store_description);
        }
    }

    public static function mobileNumbers(): array
    {
        return [['09189876543', true], ['+639189876543', true], ['0918987654', false], ['091898765432', false], ['0918-ABC-6543', false], ['08189876543', false]];
    }

    #[DataProvider('mobileNumbers')]
    public function test_sel_prof_007_preserves_existing_phone_normalization(string $number, bool $valid): void
    {
        $seller = Seller::factory()->create();
        $response = $this->actingAs($seller, 'seller')->patch(route('seller.account.update'), $this->data($seller, ['contact_no' => $number]));
        if ($valid) {
            $response->assertSessionHasNoErrors();
            $this->assertSame('09189876543', $seller->fresh()->contact_no);
        } else {
            $response->assertSessionHasErrors('contact_no');
            $this->assertSame($seller->contact_no, $seller->fresh()->contact_no);
        }
    }

    public static function images(): array
    {
        $cases = [];
        foreach (['logo', 'banner'] as $field) {
            foreach (['4.9', '5.0', '5121kb', '5.1', 'document', 'executable', 'renamed', 'webp'] as $kind) {
                $cases[] = [$field, $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('images')]
    public function test_sel_prof_008_upload_limits_and_previous_media_preservation(string $field, string $kind): void
    {
        $seller = Seller::factory()->create();
        $column = 'store_'.$field.'_path';
        $old = 'seller-'.$field.'s/'.$seller->id.'/original.png';
        Storage::disk('public')->put($old, 'previous image');
        $seller->forceFill([$column => $old])->save();
        $bad = UploadedFile::fake()->createWithContent('bad.tmp', 'MZ executable content');
        $upload = match ($kind) {
            '4.9' => UploadedFile::fake()->image('photo.jpg')->size(5018),
            '5.0' => UploadedFile::fake()->image('photo.png')->size(5120),
            '5121kb' => UploadedFile::fake()->image('photo.png')->size(5121),
            '5.1' => UploadedFile::fake()->image('photo.jpg')->size(5223),
            'document' => UploadedFile::fake()->create('price_list.docx', 20),
            'executable' => UploadedFile::fake()->create('installer.exe', 20),
            'renamed' => new UploadedFile($bad->getPathname(), 'renamed_exe.jpg', null, null, true),
            'webp' => UploadedFile::fake()->image('photo.webp'),
        };
        $response = $this->actingAs($seller, 'seller')->patch(route('seller.account.update'), $this->data($seller, [$field => $upload]));
        if (in_array($kind, ['4.9', '5.0'], true)) {
            $response->assertSessionHasNoErrors();
            $this->assertNotSame($old, $seller->fresh()->{$column});
            Storage::disk('public')->assertExists($seller->fresh()->{$column});
            Storage::disk('public')->assertMissing($old);
        } else {
            $response->assertSessionHasErrors($field);
            $this->assertSame($old, $seller->fresh()->{$column});
            Storage::disk('public')->assertExists($old);
            $this->assertSame([$old], Storage::disk('public')->allFiles());
        }
    }

    public function test_banner_can_be_kept_replaced_and_removed_without_changing_contacts(): void
    {
        $seller = Seller::factory()->create();
        $data = $this->data($seller);
        $this->actingAs($seller, 'seller')->patch(route('seller.account.update'), $data + ['banner' => UploadedFile::fake()->image('banner.png')])->assertSessionHasNoErrors();
        $path = $seller->fresh()->store_banner_path;
        $this->patch(route('seller.account.update'), $data)->assertSessionHasNoErrors();
        $this->assertSame($path, $seller->fresh()->store_banner_path);
        $this->patch(route('seller.account.update'), $data + ['remove_banner' => '1'])->assertSessionHasNoErrors();
        $this->assertNull($seller->fresh()->store_banner_path);
        Storage::disk('public')->assertMissing($path);
        $this->assertSame($seller->contact_no, $seller->fresh()->contact_no);
    }

    public static function scripts(): array
    {
        return [["<b>Sale!</b><script>alert('x')</script>"], ['"><img src=x onerror=alert(1)>']];
    }

    #[DataProvider('scripts')]
    public function test_sel_prof_012_public_description_and_search_results_escape_text(string $text): void
    {
        $seller = Seller::factory()->create();
        $this->actingAs($seller, 'seller')->patch(route('seller.account.update'), $this->data($seller, ['store_description' => $text]))->assertSessionHasNoErrors();
        $this->get(route('buyer.stores.show', $seller))->assertSee(e($text), false)->assertDontSee($text, false);
        $this->get(route('buyer.search.index', ['q' => 'Maria Clara']))->assertSee(e($text), false)->assertDontSee($text, false);
    }

    public static function foreignIds(): array
    {
        return [['seller_id'], ['id'], ['store_id']];
    }

    #[DataProvider('foreignIds')]
    public function test_sel_prof_013_tampered_store_identifiers_are_forbidden(string $field): void
    {
        $seller = Seller::factory()->create();
        $other = Seller::factory()->create();
        $before = $other->fresh()->getRawOriginal();
        $this->actingAs($seller, 'seller')->patchJson(route('seller.account.update'), $this->data($seller, [$field => $other->id]))->assertForbidden();
        $this->get('/seller/account/'.$other->id)->assertNotFound();
        $this->assertSame($before, $other->fresh()->getRawOriginal());
        $this->assertSame($seller->business_name, $seller->fresh()->business_name);
    }

    public static function roles(): array
    {
        return [['buyer', Buyer::class], ['admin', Admin::class], ['logistics', LogisticsProvider::class], ['rider', Rider::class]];
    }

    #[DataProvider('roles')]
    public function test_other_roles_cannot_update_seller_profiles(string $guard, string $model): void
    {
        $seller = Seller::factory()->create();
        $this->actingAs($model::factory()->create(), $guard)->patchJson(route('seller.account.update'), $this->data($seller))->assertUnauthorized();
        $this->assertSame($seller->business_name, $seller->fresh()->business_name);
    }

    public function test_search_excludes_unapproved_archived_stores_and_treats_wildcards_literally(): void
    {
        $one = Seller::factory()->create(['business_name' => 'Matching Store']);
        Seller::factory()->pending()->create(['business_name' => 'Matching Pending']);
        $archived = Seller::factory()->create(['business_name' => 'Matching Archived']);
        $archived->forceFill(['archived_at' => now()])->save();
        $this->get(route('buyer.search.index', ['q' => 'matching']))->assertViewHas('stores', fn ($stores) => $stores->modelKeys() === [$one->id]);
        $this->get(route('buyer.search.index', ['q' => '%%']))->assertViewHas('stores', fn ($stores) => $stores->isEmpty());
        $this->get(route('buyer.stores.show', $archived))->assertNotFound();
    }

    public function test_existing_duplicate_names_are_not_backfilled_or_renamed(): void
    {
        $one = Seller::factory()->create(['business_name' => 'Legacy Same']);
        $two = Seller::factory()->create(['business_name' => ' legacy SAME ']);
        $this->actingAs($one, 'seller')->get(route('seller.account.index'))->assertOk();
        $this->get(route('buyer.stores.show', $one))->assertOk();
        $this->assertSame('Legacy Same', $one->fresh()->business_name);
        $this->assertSame(' legacy SAME ', $two->fresh()->business_name);
        $this->assertNull($one->fresh()->store_name_key);
        $this->assertNull($two->fresh()->store_name_key);
    }

    public function test_store_names_are_escaped_in_the_page_title_as_well_as_visible_content(): void
    {
        $seller = Seller::factory()->create();
        $name = '</title><svg/onload=alert(1)>';
        $this->actingAs($seller, 'seller')->patch(route('seller.account.update'), $this->data($seller, ['business_name' => $name]))->assertSessionHasNoErrors();
        $this->get(route('buyer.stores.show', $seller))->assertSee(e($name), false)->assertDontSee($name, false);
        $this->get(route('seller.account.index'))->assertSee(e($name), false)->assertDontSee($name, false);
    }

    public function test_a_duplicate_name_rolls_back_both_uploads_and_keeps_existing_media(): void
    {
        Seller::factory()->create(['business_name' => 'Taken Store']);
        $seller = Seller::factory()->create();
        $logo = 'seller-logos/'.$seller->id.'/old.png';
        $banner = 'seller-banners/'.$seller->id.'/old.png';
        Storage::disk('public')->put($logo, 'previous logo');
        Storage::disk('public')->put($banner, 'previous banner');
        $seller->forceFill(['store_logo_path' => $logo, 'store_banner_path' => $banner])->save();
        $before = $seller->fresh()->getRawOriginal();
        $this->actingAs($seller, 'seller')->patch(route('seller.account.update'), $this->data($seller, [
            'business_name' => 'taken STORE', 'logo' => UploadedFile::fake()->image('new.png'), 'banner' => UploadedFile::fake()->image('new.jpg'),
        ]))->assertSessionHasErrors('business_name');
        $this->assertSame($before, $seller->fresh()->getRawOriginal());
        $this->assertEqualsCanonicalizing([$logo, $banner], Storage::disk('public')->allFiles());
    }

    public static function invalidProfileEdits(): array
    {
        return [['store_description', str_repeat('x', 501)], ['contact_no', '08189876543']];
    }

    #[DataProvider('invalidProfileEdits')]
    public function test_rejected_profile_edit_keeps_both_old_images(string $field, string $value): void
    {
        $seller = Seller::factory()->create();
        $logo = 'seller-logos/'.$seller->id.'/old.png';
        $banner = 'seller-banners/'.$seller->id.'/old.png';
        Storage::disk('public')->put($logo, 'previous logo');
        Storage::disk('public')->put($banner, 'previous banner');
        $seller->forceFill(['store_logo_path' => $logo, 'store_banner_path' => $banner])->save();
        $before = $seller->fresh()->getRawOriginal();
        $this->actingAs($seller, 'seller')->patch(route('seller.account.update'), $this->data($seller, [
            $field => $value, 'logo' => UploadedFile::fake()->image('new.png'), 'banner' => UploadedFile::fake()->image('new.jpg'),
        ]))->assertSessionHasErrors($field);
        $this->assertSame($before, $seller->fresh()->getRawOriginal());
        $this->assertEqualsCanonicalizing([$logo, $banner], Storage::disk('public')->allFiles());
    }
}
