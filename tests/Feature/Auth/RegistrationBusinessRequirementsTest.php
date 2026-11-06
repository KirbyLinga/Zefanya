<?php

namespace Tests\Feature\Auth;

use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationBusinessRequirementsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function payload(string $role): array
    {
        Storage::fake('local');
        Notification::fake();
        $data = [
            'first_name' => 'Maria Clara', 'last_name' => 'Santos', 'sex' => 'female',
            'email' => 'business@example.test', 'password' => 'Registration@2026', 'password_confirmation' => 'Registration@2026',
            'contact_no' => '09171234567', 'birthday' => '1990-01-15', 'address_mode' => 'api', 'region_code' => '030000000',
            'province' => '030540000', 'province_name' => 'Pampanga', 'municipality' => '030540100', 'municipality_name' => 'San Fernando',
            'barangay' => '0305401001', 'barangay_name' => 'Barangay One', 'street' => '123 Rizal Street', 'postal_code' => '2000',
            'business_name' => 'Business Requirements Shop', 'tin' => '123-456-789-000',
            'upload_id' => UploadedFile::fake()->createWithContent('id.pdf', "%PDF-1.4\nID"), 'terms' => '1',
        ];
        if ($role === 'seller') {
            $data += ['seller_type' => 'sole_proprietor', 'line_of_business_id' => Category::create(['label' => 'Business Tests', 'icon' => 'store'])->id,
                'business_permit' => UploadedFile::fake()->createWithContent('permit.pdf', "%PDF-1.4\nPermit")];
        } else {
            $data += ['sec_number' => 'AB123456789', 'service_areas' => ['030540100', '030540200'],
                'dti_permit_upload' => UploadedFile::fake()->createWithContent('permit.pdf', "%PDF-1.4\nPermit")];
        }

        return $data;
    }

    public function test_corporation_certificate_is_stored_privately_and_only_admin_can_view_it(): void
    {
        $data = $this->payload('seller');
        $data['seller_type'] = 'corporation';
        $data['sec_certificate'] = UploadedFile::fake()->createWithContent('sec.pdf', "%PDF-1.4\nCertificate");
        $this->postJson(route('register.seller.store'), $data)->assertCreated();
        $seller = Seller::query()->sole();
        $this->assertSame('corporation', $seller->seller_type);
        $this->assertSame('030000000', $seller->region_code);
        Storage::disk('local')->assertExists($seller->sec_certificate_path);
        $url = route('admin.documents.stream', ['type' => 'seller', 'id' => $seller->id, 'document' => 'sec_certificate']);
        $this->get($url)->assertRedirect();
        $this->actingAs(Admin::factory()->create(['role' => 'moderator']), 'admin')->get($url)->assertForbidden();
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin')->get($url)->assertOk();
        $this->assertDatabaseHas('admin_document_access_logs', ['admin_id' => $admin->id, 'document_key' => 'sec_certificate', 'document_id' => $seller->id]);
    }

    public function test_non_corporations_do_not_store_an_unneeded_sec_certificate(): void
    {
        $data = $this->payload('seller');
        $data['sec_certificate'] = UploadedFile::fake()->createWithContent('sec.pdf', "%PDF-1.4\nCertificate");
        $this->postJson(route('register.seller.store'), $data)->assertCreated();
        $this->assertNull(Seller::query()->sole()->sec_certificate_path);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    public function test_invalid_certificate_content_is_rejected_for_a_corporation(): void
    {
        $data = $this->payload('seller');
        $data['seller_type'] = 'corporation';
        $file = tmpfile();
        fwrite($file, 'MZ executable content');
        $data['sec_certificate'] = new UploadedFile(stream_get_meta_data($file)['uri'], 'sec.pdf', null, null, true);
        $this->postJson(route('register.seller.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('sec_certificate');
        $this->assertDatabaseCount('sellers', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        fclose($file);
    }

    public function test_mail_failure_removes_all_corporation_documents(): void
    {
        $data = $this->payload('seller');
        $data['seller_type'] = 'corporation';
        $data['sec_certificate'] = UploadedFile::fake()->createWithContent('sec.pdf', "%PDF-1.4\nCertificate");
        $this->mock(Dispatcher::class)->shouldReceive('send')->once()->andThrow(new \RuntimeException('Mail unavailable'));
        $this->postJson(route('register.seller.store'), $data)->assertServiceUnavailable();
        $this->assertDatabaseCount('sellers', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_logistics_stores_multiple_city_service_areas_and_normalized_sec_number(): void
    {
        $data = $this->payload('logistics');
        $data['sec_number'] = 'ab123456789';
        $this->postJson(route('register.logistics.store'), $data)->assertCreated();
        $provider = LogisticsProvider::query()->sole();
        $this->assertSame('AB123456789', $provider->sec_number);
        $this->assertSame(['030540100', '030540200'], $provider->service_areas);
        $this->assertNotNull($provider->company_name_key);
        $this->assertSame('030000000', $provider->region_code);
    }

    public static function invalidLogisticsFields(): array
    {
        return [
            ['sec_number', 'A123456789'], ['sec_number', 'ABC123456789'], ['sec_number', 'AB12345678'], ['sec_number', 'AB1234567890'], ['sec_number', ''],
            ['service_areas', []], ['service_areas', '030540100'], ['service_areas', ['030540100', '030540100']], ['service_areas', ['not-a-city']],
        ];
    }

    #[DataProvider('invalidLogisticsFields')]
    public function test_invalid_logistics_business_fields_are_rejected(string $field, mixed $value): void
    {
        $data = $this->payload('logistics');
        $data[$field] = $value;
        $response = $this->postJson(route('register.logistics.store'), $data)->assertUnprocessable();
        $this->assertTrue(collect(array_keys($response->json('errors')))->contains(fn ($key) => $key === $field || str_starts_with($key, $field.'.')));
        $this->assertDatabaseCount('logistics_providers', 0);
    }

    public function test_duplicate_sec_number_and_company_name_are_rejected(): void
    {
        $data = $this->payload('logistics');
        LogisticsProvider::factory()->create(['sec_number' => 'AB123456789', 'business_name' => 'BUSINESS REQUIREMENTS SHOP']);
        $this->postJson(route('register.logistics.store'), $data)->assertUnprocessable()->assertJsonValidationErrors(['sec_number', 'business_name']);
    }

    public static function postalCodes(): array
    {
        $cases = [];
        foreach (['seller', 'logistics'] as $role) {
            foreach (['123', '12345', 'ABCD', '12 4'] as $postal) {
                $cases[] = [$role, $postal];
            }
        }

        return $cases;
    }

    #[DataProvider('postalCodes')]
    public function test_postal_code_must_be_four_digits(string $role, string $postal): void
    {
        $data = $this->payload($role);
        $data['postal_code'] = $postal;
        $this->postJson(route('register.'.$role.'.store'), $data)->assertUnprocessable()->assertJsonPath('errors.postal_code.0', 'Postal code must contain exactly 4 digits.');
    }

    public static function validPostalCodes(): array
    {
        return [['seller', '4030'], ['seller', '0123'], ['logistics', '4030'], ['logistics', '0123']];
    }

    #[DataProvider('validPostalCodes')]
    public function test_four_digit_postal_codes_preserve_leading_zeros(string $role, string $postal): void
    {
        $data = $this->payload($role);
        $data['postal_code'] = $postal;
        $this->postJson(route('register.'.$role.'.store'), $data)->assertCreated();
        $account = $role === 'seller' ? Seller::query()->sole() : LogisticsProvider::query()->sole();
        $this->assertSame($postal, $account->postal_code);
    }
}
