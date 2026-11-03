<?php

namespace Tests\Feature;

use App\Enums\LogisticsProviderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Step 2: mass-assignment guard tests.
 *
 * Every test sends a dangerous key in the request body and asserts
 * the server ignores it entirely — the row is created/updated without
 * that key being applied.
 *
 * Mutation: if 'status' is put back in $fillable on Buyer, the
 * test_buyer_registration_ignores_status test fails.
 */
class MassAssignmentGuardTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ── Buyer registration ───────────────────────────────────────────────────

    private function buyerPayload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'sex' => 'female',
            'email' => 'jane'.uniqid().'@example.com',
            'password' => 'Password1!',
            'password_confirmation' => 'Password1!',
            'contact_no' => '09171234567',
            'birthday' => '1995-06-20',
            'address_mode' => 'manual',
            'street' => '123 Rizal St.',
            'upload_id' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
        ], $overrides);
    }

    public function test_buyer_registration_ignores_status(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->post(
            route('api.register.buyer.store'),
            $this->buyerPayload(['status' => 'approved']),
            ['Accept' => 'application/json'],
        )->assertStatus(201);

        $buyer = Buyer::sole();
        $this->assertSame('pending_verification', $buyer->status);
        $this->assertNotSame('approved', $buyer->status);
    }

    public function test_buyer_registration_ignores_email_verified_at(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->post(
            route('api.register.buyer.store'),
            $this->buyerPayload(['email_verified_at' => now()->toDateTimeString()]),
            ['Accept' => 'application/json'],
        )->assertStatus(201);

        $buyer = Buyer::sole();
        $this->assertNull($buyer->email_verified_at);
    }

    public function test_buyer_registration_ignores_role(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->post(
            route('api.register.buyer.store'),
            $this->buyerPayload(['role' => 'admin']),
            ['Accept' => 'application/json'],
        )->assertStatus(201);

        // The column doesn't exist on buyers, but the request must not fail
        // or expose any admin escalation.
        $this->assertSame(1, Buyer::count());
    }

    // ── Google registration ──────────────────────────────────────────────────

    public function test_google_buyer_registration_ignores_status(): void
    {
        Storage::fake('local');
        config(['services.google.client_ids' => ['test-client-id']]);

        $fake = new class implements GoogleIdTokenVerifier
        {
            public function verify(string $idToken): array
            {
                return [
                    'sub' => 'google-sub-123',
                    'email' => 'user@gmail.com',
                    'email_verified' => true,
                    'name' => 'Test User',
                    'iss' => 'https://accounts.google.com',
                    'aud' => 'test-client-id',
                    'exp' => 4102444800,
                ];
            }
        };
        app()->instance(GoogleIdTokenVerifier::class, $fake);

        $this->post(
            route('api.auth.google'),
            [
                'id_token' => 'fake-token',
                'role' => 'buyer',
                'first_name' => 'Google',
                'last_name' => 'User',
                'sex' => 'male',
                'contact_no' => '09181234567',
                'birthday' => '1998-01-01',
                'street' => '123 Main St',
                'province_name' => 'Cebu',
                'municipality_name' => 'Cebu City',
                'barangay_name' => 'Lahug',
                'upload_id' => UploadedFile::fake()->create('id.jpg', 300, 'image/jpeg'),
                // Dangerous fields attempted
                'status' => 'approved',
                'email_verified_at' => now()->toDateTimeString(),
                'is_admin' => true,
            ],
            ['Accept' => 'application/json'],
        )->assertStatus(201);

        $buyer = Buyer::sole();
        $this->assertSame('pending_approval', $buyer->status);
        $this->assertNotNull($buyer->email_verified_at); // set by forceFill in controller — that's intentional
    }

    // ── Profile update ───────────────────────────────────────────────────────

    public function test_buyer_profile_update_ignores_status(): void
    {
        $buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        // There is currently no profile-update route on the API, but we test
        // the model layer directly: fill() with status should not apply it.
        $buyer->fill(['status' => 'rejected', 'first_name' => 'Updated']);
        $this->assertNotSame('rejected', $buyer->status);
        $this->assertSame('Updated', $buyer->first_name);
    }

    // ── Admin approve/reject: model-level forceFill test ────────────────────

    public function test_admin_approval_uses_forcefill_not_mass_assignment(): void
    {
        // Prove that $courier->fill(['status' => 'approved']) does NOT change
        // the status (it's guarded), while forceFill does.
        $courier = LogisticsProvider::factory()->pendingApproval()->create();

        // Simulate what fill() would do (wrong — mass-assignment path):
        $courier->fill(['status' => LogisticsProviderStatus::Approved->value]);
        $this->assertNotEquals(
            LogisticsProviderStatus::Approved,
            $courier->status,
            'fill() should NOT change a guarded attribute.',
        );

        // Simulate what the admin service correctly does (forceFill):
        $courier->forceFill(['status' => LogisticsProviderStatus::Approved])->save();
        $this->assertSame(
            LogisticsProviderStatus::Approved,
            $courier->fresh()->status,
            'forceFill() should change the status.',
        );
    }

    public function test_seller_approval_uses_forcefill_not_mass_assignment(): void
    {
        $seller = Seller::factory()->create(['status' => 'pending_approval']);

        // fill() with status should not apply it (guarded):
        $seller->fill(['status' => 'approved']);
        $this->assertNotSame('approved', $seller->status);

        // forceFill does apply it:
        $seller->forceFill(['status' => 'approved'])->save();
        $this->assertSame('approved', $seller->fresh()->status);
    }
}
