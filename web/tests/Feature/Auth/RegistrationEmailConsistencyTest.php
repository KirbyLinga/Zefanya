<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Buyer\StoreBuyerRegistrationRequest;
use App\Http\Requests\Logistics\StoreLogisticsRegistrationRequest;
use App\Http\Requests\Seller\StoreSellerRegistrationRequest;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use App\Notifications\Buyer\BuyerVerifyOtp;
use App\Notifications\Logistics\LogisticsRegistrationOtp;
use App\Notifications\Seller\SellerRegistrationOtp;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationEmailConsistencyTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const MODELS = ['buyer' => Buyer::class, 'seller' => Seller::class, 'logistics' => LogisticsProvider::class];

    private const REQUESTS = ['buyer' => StoreBuyerRegistrationRequest::class, 'seller' => StoreSellerRegistrationRequest::class, 'logistics' => StoreLogisticsRegistrationRequest::class];

    private function payload(string $role, string $email = 'new@example.test'): array
    {
        $payload = [
            'last_name' => 'Santos', 'first_name' => 'Maria Clara', 'sex' => 'female',
            'email' => $email, 'password' => 'Registration@2026', 'password_confirmation' => 'Registration@2026',
            'contact_no' => '09171234567', 'birthday' => '1990-01-15', 'address_mode' => 'api',
            'province' => '0300000000', 'province_name' => 'Batangas',
            'municipality' => '0306800000', 'municipality_name' => 'Lipa City',
            'barangay' => '030680001', 'barangay_name' => 'Barangay One', 'street' => '123 Rizal Street',
            'upload_id' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'), 'terms' => '1',
        ];
        if ($role !== 'buyer') {
            $payload['business_name'] = 'Registration Test Business';
            $payload['tin'] = '123-456-789-000';
            $payload['region_code'] = '030000000';
        }
        if ($role === 'seller') {
            $payload['seller_type'] = 'sole_proprietor';
            $payload['line_of_business_id'] = Category::firstOrCreate(['label' => 'Registration Email Tests'], ['icon' => 'store'])->id;
            $payload['business_permit'] = UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf');
        }
        if ($role === 'logistics') {
            $payload['sec_number'] = 'AB123456789';
            $payload['service_areas'] = ['030680000'];
            $payload['dti_permit_upload'] = UploadedFile::fake()->create('permit.pdf', 100, 'application/pdf');
        }

        return $payload;
    }

    private function fakeRegistrationEffects(): void
    {
        Storage::fake('local');
        Notification::fake([BuyerVerifyOtp::class, SellerRegistrationOtp::class, LogisticsRegistrationOtp::class]);
    }

    public static function conflicts(): array
    {
        $cases = [];
        foreach (array_keys(self::MODELS) as $target) {
            foreach (array_keys(self::MODELS) as $owner) {
                foreach (['approved', 'pending', 'rejected', 'suspended'] as $status) {
                    if ($target === $owner && $status === 'rejected') {
                        continue; // Existing same-role archive-and-retry remains supported.
                    }
                    $cases["{$target}-{$owner}-{$status}"] = [$target, $owner, $status];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('conflicts')]
    public function test_owned_emails_are_rejected_case_insensitively_without_changing_the_account(string $target, string $owner, string $status): void
    {
        $this->fakeRegistrationEffects();
        $existing = self::MODELS[$owner]::factory()->create(['email' => 'Existing@Example.test', 'status' => $status, 'contact_no' => '09991234567']);
        $snapshot = $existing->fresh()->getRawOriginal();
        $this->postJson(route("register.{$target}.store"), $this->payload($target, ' EXISTING@EXAMPLE.TEST '))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame($snapshot, $existing->fresh()->getRawOriginal());
        $this->assertSame($owner === $target ? 1 : 0, self::MODELS[$target]::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Notification::assertNothingSent();
    }

    public static function retries(): array
    {
        $cases = [];
        foreach (array_keys(self::MODELS) as $role) {
            foreach (['rejected', 'unverified'] as $status) {
                $cases[] = [$role, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('retries')]
    public function test_case_insensitive_same_role_retry_archives_only_eligible_old_applications(string $role, string $status): void
    {
        $this->fakeRegistrationEffects();
        $existing = self::MODELS[$role]::factory()->create([
            'email' => 'Retry@Example.test', 'status' => $status, 'contact_no' => '09171234567',
            'created_at' => now()->subMinutes(20), 'email_verification_expires_at' => now()->subMinute(),
            'rejection_reason' => $status === 'rejected' ? 'Original decision' : null,
        ]);
        $response = $this->postJson(route("register.{$role}.store"), $this->payload($role, ' RETRY@EXAMPLE.TEST '));
        if ($role === 'buyer') {
            $response->assertOk();
        } else {
            $response->assertCreated();
        }
        $archived = self::MODELS[$role]::withoutGlobalScope('not_archived')->findOrFail($existing->id);
        $this->assertNotNull($archived->archived_at);
        $this->assertSame('Retry@Example.test', $archived->registration_email);
        $this->assertSame($status === 'rejected' ? 'Original decision' : null, $archived->rejection_reason);
        $new = self::MODELS[$role]::where('email', 'retry@example.test')->sole();
        $this->assertNotSame($existing->id, $new->id);
        $this->assertSame('unverified', $new->getRawOriginal('status'));
        $notification = match ($role) {
            'buyer' => BuyerVerifyOtp::class, 'seller' => SellerRegistrationOtp::class, 'logistics' => LogisticsRegistrationOtp::class,
        };
        Notification::assertSentToTimes($new, $notification, 1);
    }

    public static function roles(): array
    {
        return [['buyer'], ['seller'], ['logistics']];
    }

    #[DataProvider('roles')]
    public function test_fresh_unverified_registration_still_reserves_its_email(string $role): void
    {
        $this->fakeRegistrationEffects();
        $existing = self::MODELS[$role]::factory()->create(['email' => 'fresh@example.test', 'status' => 'unverified', 'created_at' => now(), 'email_verification_expires_at' => now()->addMinutes(10)]);
        $this->postJson(route("register.{$role}.store"), $this->payload($role, $existing->email))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(1, self::MODELS[$role]::count());
        Notification::assertNothingSent();
    }

    public static function lateConflicts(): array
    {
        $cases = [];
        foreach (array_keys(self::MODELS) as $role) {
            foreach (['email', 'contact_no', 'other_role'] as $field) {
                $cases[] = [$role, $field];
            }
        }
        $cases[] = ['seller', 'tin'];
        $cases[] = ['seller', 'business_name'];
        $cases[] = ['logistics', 'tin'];

        return $cases;
    }

    #[DataProvider('lateConflicts')]
    public function test_conflict_created_after_validation_returns_field_errors_and_cleans_new_uploads(string $role, string $field): void
    {
        $this->fakeRegistrationEffects();
        $payload = $this->payload($role);
        $lateAccount = null;
        $this->app->afterResolving(self::REQUESTS[$role], function () use ($role, $field, &$lateAccount): void {
            $owner = $field === 'other_role' ? ($role === 'buyer' ? 'seller' : 'buyer') : $role;
            $lateAccount = self::MODELS[$owner]::factory()->create([
                'email' => in_array($field, ['contact_no', 'tin', 'business_name'], true) ? 'another@example.test' : 'new@example.test',
                'contact_no' => $field === 'contact_no' ? '09171234567' : '09991234567',
                'status' => 'approved',
                ...($field === 'tin' ? ['tin' => '123-456-789-000'] : []),
                ...($field === 'business_name' ? ['business_name' => 'Registration Test Business', 'store_name_key' => hash('sha256', Seller::normalizedStoreName('Registration Test Business'))] : []),
            ]);
        });
        $this->postJson(route("register.{$role}.store"), $payload)->assertUnprocessable()
            ->assertJsonValidationErrors($field === 'other_role' ? 'email' : $field);
        $this->assertNotNull($lateAccount);
        $this->assertNotNull($lateAccount->fresh());
        $this->assertSame([], Storage::disk('local')->allFiles());
        Notification::assertNothingSent();
    }

    #[DataProvider('roles')]
    public function test_failed_retry_keeps_existing_account_documents_when_a_phone_race_rolls_back(string $role): void
    {
        $this->fakeRegistrationEffects();
        $attributes = ['email' => 'new@example.test', 'status' => 'rejected', 'contact_no' => null, 'upload_id_path' => 'keep/original-id.pdf'];
        if ($role === 'seller') {
            $attributes['business_permit_path'] = 'keep/original-permit.pdf';
        } elseif ($role === 'logistics') {
            $attributes['dti_permit_path'] = 'keep/original-permit.pdf';
        }
        $existing = self::MODELS[$role]::factory()->create($attributes);
        $snapshot = $existing->fresh()->getRawOriginal();
        Storage::disk('local')->put('keep/original-id.pdf', 'existing document');
        if ($role !== 'buyer') {
            Storage::disk('local')->put('keep/original-permit.pdf', 'existing permit');
        }
        $payload = $this->payload($role);
        $this->app->afterResolving(self::REQUESTS[$role], function () use ($role): void {
            self::MODELS[$role]::factory()->create(['email' => 'another@example.test', 'contact_no' => '09171234567', 'status' => 'approved']);
        });
        $this->postJson(route("register.{$role}.store"), $payload)->assertUnprocessable()->assertJsonValidationErrors('contact_no');
        $this->assertSame($snapshot, $existing->fresh()->getRawOriginal());
        Storage::disk('local')->assertExists('keep/original-id.pdf');
        if ($role !== 'buyer') {
            Storage::disk('local')->assertExists('keep/original-permit.pdf');
        }
        Notification::assertNothingSent();
    }
}
