<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Auth\ResubmitRegistrationRequest;
use App\Models\Admin\AccountActionHistory;
use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use App\Notifications\Logistics\LogisticsRegistrationDecision;
use App\Notifications\Seller\SellerRegistrationDecision;
use App\Support\SignedRegistrationResubmission;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** TC-SEL-042 and TC-LOG-042: correct and resubmit the existing rejected application. */
class RegistrationResubmissionTest extends TestCase
{
    use LazilyRefreshDatabase;

    public static function roles(): array
    {
        return [['seller'], ['logistics']];
    }

    private function applicant(string $role): mixed
    {
        Storage::fake('local');
        Notification::fake();
        $attributes = [
            'status' => 'rejected', 'email_verified_at' => now(), 'rejection_reason' => 'Replace the permit.',
            'password' => 'Applicant@2026', 'business_name' => 'Original Company', 'tin' => '123-456-789-000',
            'contact_no' => '09171234567', 'region_code' => '030000000', 'province_code' => '030540000',
            'municipality_code' => '030540100', 'barangay_code' => '0305401001',
            'upload_id_path' => 'original/id.pdf',
        ];
        if ($role === 'seller') {
            $attributes += ['seller_type' => 'sole_proprietor', 'business_permit_path' => 'original/permit.pdf',
                'line_of_business_id' => Category::create(['label' => 'Resubmission Tests', 'icon' => 'store'])->id];
            $account = Seller::factory()->create($attributes);
        } else {
            $attributes += ['sec_number' => 'AB123456789', 'service_areas' => ['030540100'], 'dti_permit_path' => 'original/permit.pdf'];
            $account = LogisticsProvider::factory()->create($attributes);
        }
        Storage::disk('local')->put('original/id.pdf', "%PDF-1.4\nOriginal ID");
        Storage::disk('local')->put('original/permit.pdf', "%PDF-1.4\nOriginal permit");

        return $account;
    }

    private function payload($account): array
    {
        $data = [
            'first_name' => 'Maria Clara', 'last_name' => 'Santos', 'sex' => 'female',
            'email' => $account->email, 'contact_no' => '09171234567', 'birthday' => '1990-01-15',
            'address_mode' => 'api', 'region_code' => '030000000', 'province' => '030540000', 'province_name' => 'Pampanga',
            'municipality' => '030540100', 'municipality_name' => 'San Fernando', 'barangay' => '0305401001', 'barangay_name' => 'Barangay One',
            'street' => '123 Corrected Street', 'postal_code' => '2000', 'business_name' => 'Corrected Company',
            'tin' => '123-456-789-000', 'terms' => '1',
        ];
        if ($account instanceof Seller) {
            $data += ['seller_type' => 'sole_proprietor', 'line_of_business_id' => $account->line_of_business_id];
        } else {
            $data += ['sec_number' => 'AB123456789', 'service_areas' => ['030540100', '030540200']];
        }

        return $data;
    }

    #[DataProvider('roles')]
    public function test_signed_page_prefills_the_rejected_application_without_exposing_documents_or_password(string $role): void
    {
        $account = $this->applicant($role);
        $this->get(SignedRegistrationResubmission::url($account))->assertOk()
            ->assertSee('Original Company')->assertSee('Replace the permit.')
            ->assertSee($account->email)->assertDontSee($account->getAuthPassword(), false)->assertDontSee('original/permit.pdf');
    }

    #[DataProvider('roles')]
    public function test_fields_and_one_document_can_be_corrected_and_same_application_returns_to_pending(string $role): void
    {
        $account = $this->applicant($role);
        $password = $account->password;
        $verifiedAt = $account->email_verified_at->toDateTimeString();
        // Preserve the original admin decision as history when the row returns to pending.
        $admin = Admin::factory()->create();
        $history = (new AccountActionHistory)->forceFill([
            'admin_id' => $admin->id, 'admin_name' => $admin->name, 'admin_email' => $admin->email,
            'account_type' => $role, 'account_id' => $account->id, 'account_name' => $account->fullName(), 'account_email' => $account->email,
            'action' => 'rejected', 'from_status' => 'pending', 'to_status' => 'rejected', 'reason' => 'Replace the permit.',
        ]);
        $history->save();
        $data = $this->payload($account);
        $data[$role === 'seller' ? 'business_permit' : 'dti_permit_upload'] = UploadedFile::fake()->createWithContent('corrected.pdf', "%PDF-1.4\nCorrected permit");
        $data += ['status' => 'approved', 'approved_by' => $admin->id, 'password' => 'Hacked@2026'];
        $url = SignedRegistrationResubmission::url($account);
        $this->post($url, $data)->assertRedirect(route('register.'.$role.'.pending'));
        $current = $account->fresh();
        $this->assertSame('pending', $current->getRawOriginal('status'));
        $this->assertSame('Corrected Company', $current->business_name);
        $this->assertSame('123 Corrected Street', $current->street);
        $this->assertSame($account->email, $current->email);
        $this->assertSame($password, $current->password);
        $this->assertSame($verifiedAt, $current->email_verified_at->toDateTimeString());
        $this->assertNull($current->rejection_reason);
        $this->assertNull($current->approved_by);
        $this->assertNull($current->approved_at);
        $this->assertSame('original/id.pdf', $current->upload_id_path);
        $path = $role === 'seller' ? $current->business_permit_path : $current->dti_permit_path;
        Storage::disk('local')->assertExists($path);
        Storage::disk('local')->assertMissing('original/permit.pdf');
        Storage::disk('local')->assertExists('original/id.pdf');
        $this->assertSame('Replace the permit.', $history->fresh()->reason);
        $this->assertDatabaseCount($current->getTable(), 1);
        Notification::assertNothingSent();
        $this->post($url, $this->payload($current))->assertForbidden();
        $this->actingAs($admin, 'admin')->get(route('admin.registrations.index', ['type' => $role, 'status' => 'pending']))
            ->assertOk()->assertSee('Corrected Company')->assertSee($account->email);
    }

    #[DataProvider('roles')]
    public function test_unsigned_tampered_or_expired_links_cannot_edit_an_application(string $role): void
    {
        $account = $this->applicant($role);
        $data = $this->payload($account);
        $unsigned = route('registration.resubmit', ['role' => $role, 'application' => $account->id]);
        $this->get($unsigned)->assertForbidden();
        $this->post($unsigned, $data)->assertForbidden();
        $url = SignedRegistrationResubmission::url($account);
        $this->post(str_replace('/'.$account->id.'/resubmit', '/999999/resubmit', $url), $data)->assertForbidden();
        $this->travel(61)->minutes();
        $this->post($url, $data)->assertForbidden();
        $this->assertSame('rejected', $account->fresh()->getRawOriginal('status'));
        Storage::disk('local')->assertExists('original/permit.pdf');
    }

    #[DataProvider('roles')]
    public function test_existing_documents_can_be_retained_and_email_cannot_change(string $role): void
    {
        $account = $this->applicant($role);
        $data = $this->payload($account);
        $url = SignedRegistrationResubmission::url($account);
        $this->postJson($url, [...$data, 'email' => 'other@example.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->post($url, $data)->assertRedirect();
        $this->assertSame('original/id.pdf', $account->fresh()->upload_id_path);
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    #[DataProvider('roles')]
    public function test_invalid_replacement_does_not_modify_the_rejected_application(string $role): void
    {
        $account = $this->applicant($role);
        $snapshot = $account->fresh()->getRawOriginal();
        $data = $this->payload($account);
        $data['upload_id'] = UploadedFile::fake()->create('invalid.gif', 10, 'image/gif');
        $this->postJson(SignedRegistrationResubmission::url($account), $data)->assertUnprocessable()->assertJsonValidationErrors('upload_id');
        $this->assertSame($snapshot, $account->fresh()->getRawOriginal());
        $this->assertCount(2, Storage::disk('local')->allFiles());
    }

    #[DataProvider('roles')]
    public function test_failed_database_write_preserves_old_documents_and_removes_replacements(string $role): void
    {
        $account = $this->applicant($role);
        $snapshot = $account->fresh()->getRawOriginal();
        Exceptions::fake();
        $class = $role === 'seller' ? Seller::class : LogisticsProvider::class;
        $class::updating(function (): void {
            throw new \RuntimeException('Write unavailable');
        });
        try {
            $data = $this->payload($account);
            $data['upload_id'] = UploadedFile::fake()->createWithContent('new.pdf', "%PDF-1.4\nNew ID");
            $this->post(SignedRegistrationResubmission::url($account), $data)->assertServerError();
        } finally {
            $class::flushEventListeners();
        }
        $this->assertSame($snapshot, $account->fresh()->getRawOriginal());
        $this->assertCount(2, Storage::disk('local')->allFiles());
        Storage::disk('local')->assertExists('original/id.pdf');
        Storage::disk('local')->assertExists('original/permit.pdf');
    }

    #[DataProvider('roles')]
    public function test_login_and_rejection_email_provide_the_edit_link(string $role): void
    {
        $account = $this->applicant($role);
        $this->post(route('unified.login'), ['email' => $account->email, 'password' => 'Applicant@2026'])
            ->assertSessionHas('auth.resume_label', 'Edit and resubmit application');
        $url = session('auth.resume_url');
        $this->get($url)->assertOk();
        $notice = $role === 'seller' ? new SellerRegistrationDecision('rejected', 'Replace the permit.')
            : new LogisticsRegistrationDecision('rejected', 'Replace the permit.');
        $this->assertSame('Edit and resubmit application', $notice->toMail($account)->actionText);
        $this->get($notice->toMail($account)->actionUrl)->assertOk();
    }

    public static function ineligibleApplications(): array
    {
        $cases = [];
        foreach (['seller', 'logistics'] as $role) {
            foreach (['pending', 'approved', 'suspended'] as $status) {
                $cases[] = [$role, $status, true];
            }
            $cases[] = [$role, 'rejected', false];
        }

        return $cases;
    }

    #[DataProvider('ineligibleApplications')]
    public function test_only_verified_rejected_applications_can_use_an_edit_link(string $role, string $status, bool $verified): void
    {
        $account = $this->applicant($role);
        $url = SignedRegistrationResubmission::url($account);
        $account->forceFill(['status' => $status, 'email_verified_at' => $verified ? now() : null])->save();
        $this->get($url)->assertForbidden();
        $this->post($url, $this->payload($account))->assertForbidden();
    }

    #[DataProvider('roles')]
    public function test_a_decision_after_validation_cannot_be_overwritten_by_resubmission(string $role): void
    {
        $account = $this->applicant($role);
        $this->app->afterResolving(ResubmitRegistrationRequest::class, function () use ($account): void {
            $account->forceFill(['status' => 'approved'])->save();
        });
        $this->postJson(SignedRegistrationResubmission::url($account), $this->payload($account))
            ->assertUnprocessable()->assertJsonValidationErrors('application');
        $this->assertSame('approved', $account->fresh()->getRawOriginal('status'));
        $this->assertSame('Original Company', $account->fresh()->business_name);
        Storage::disk('local')->assertExists('original/permit.pdf');
    }

    #[DataProvider('roles')]
    public function test_resubmission_preserves_business_name_uniqueness(string $role): void
    {
        $account = $this->applicant($role);
        $class = $role === 'seller' ? Seller::class : LogisticsProvider::class;
        $class::factory()->create(['business_name' => 'Taken Company']);
        $data = $this->payload($account);
        $data['business_name'] = 'TAKEN COMPANY';
        $this->postJson(SignedRegistrationResubmission::url($account), $data)->assertUnprocessable()->assertJsonValidationErrors('business_name');
        $this->assertSame('rejected', $account->fresh()->getRawOriginal('status'));
    }

    public function test_changing_to_corporation_requires_a_new_sec_certificate(): void
    {
        $account = $this->applicant('seller');
        $url = SignedRegistrationResubmission::url($account);
        $data = $this->payload($account);
        $data['seller_type'] = 'corporation';
        $this->postJson($url, $data)->assertUnprocessable()->assertJsonValidationErrors('sec_certificate');
        $data['sec_certificate'] = UploadedFile::fake()->createWithContent('sec.pdf', "%PDF-1.4\nSEC certificate");
        $this->post($url, $data)->assertRedirect(route('register.seller.pending'));
        Storage::disk('local')->assertExists($account->fresh()->sec_certificate_path);
    }
}
