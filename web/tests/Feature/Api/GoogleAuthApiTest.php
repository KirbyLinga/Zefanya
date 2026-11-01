<?php

namespace Tests\Feature\Api;

use App\Enums\LogisticsProviderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Services\Auth\GoogleIdTokenVerifier;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H2: Google sign-in with a fake verifier.
 *
 * The fake stands in for Google's signature check. Everything downstream is
 * real: normalized-claim validation, email linking, role selection, and the
 * pending-rider gate. The tests that assert a rejection prove the endpoint
 * refuses unverified claims even if a verifier is too permissive.
 */
class GoogleAuthApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @var array<string, mixed> */
    private array $validClaims = [
        'sub' => 'google-sub-123',
        'email' => 'user@gmail.com',
        'email_verified' => true,
        'name' => 'Test User',
        'iss' => 'https://accounts.google.com',
        'aud' => 'test-client-id',
        'exp' => 4102444800, // 2100-01-01
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['services.google.client_ids' => ['test-client-id']]);
    }

    private function bindFakeVerifier(array $claims = [], ?\Throwable $exception = null): void
    {
        $fake = new class($claims, $exception) implements GoogleIdTokenVerifier
        {
            public function __construct(
                private array $claims,
                private ?\Throwable $exception,
            ) {}

            public function verify(string $idToken): array
            {
                if ($this->exception !== null) {
                    throw $this->exception;
                }

                return $this->claims;
            }
        };

        app()->instance(GoogleIdTokenVerifier::class, $fake);
    }

    private function postGoogle(string $idToken = 'fake-token', array $extra = []): TestResponse
    {
        $payload = array_merge(['id_token' => $idToken], $extra);
        $hasFile = collect($payload)->contains(fn ($value): bool => $value instanceof UploadedFile);

        return $hasFile
            ? $this->post(route('api.auth.google'), $payload, ['Accept' => 'application/json'])
            : $this->postJson(route('api.auth.google'), $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function buyerPayload(array $overrides = []): array
    {
        return array_merge([
            'role' => 'buyer',
            'first_name' => 'Signup',
            'last_name' => 'Buyer',
            'sex' => 'male',
            'contact_no' => '09181234567',
            'birthday' => '1998-01-01',
            'street' => '123 Main St',
            'province_name' => 'Cebu',
            'municipality_name' => 'Cebu City',
            'barangay_name' => 'Lahug',
            'upload_id' => UploadedFile::fake()->create('id.jpg', 300, 'image/jpeg'),
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function riderPayload(array $overrides = []): array
    {
        return array_merge([
            'role' => 'courier',
            'first_name' => 'Signup',
            'last_name' => 'Rider',
            'sex' => 'female',
            'contact_no' => '09191234567',
            'birthday' => '1996-03-20',
            'street' => '456 Express Rd',
            'vehicle_type' => 'Van',
            'plate_number' => 'VAN-8888',
            'license_number' => 'N02-99-123456',
            'license_expiry' => now()->addYear()->format('Y-m-d'),
            'upload_id' => UploadedFile::fake()->create('id.jpg', 300, 'image/jpeg'),
            'license_doc' => UploadedFile::fake()->create('license.jpg', 300, 'image/jpeg'),
        ], $overrides);
    }

    public function test_wrong_audience_returns_generic_401(): void
    {
        $this->bindFakeVerifier(array_merge($this->validClaims, ['aud' => 'somebody-elses-client']));

        $this->postGoogle()
            ->assertStatus(401)
            ->assertExactJson([
                'ok' => false,
                'message' => 'Invalid or unverified Google token.',
            ]);
    }

    public function test_unverified_email_returns_generic_401(): void
    {
        $this->bindFakeVerifier(array_merge($this->validClaims, ['email_verified' => false]));

        $this->postGoogle()
            ->assertStatus(401)
            ->assertExactJson([
                'ok' => false,
                'message' => 'Invalid or unverified Google token.',
            ]);
    }

    public function test_expired_token_returns_generic_401(): void
    {
        $this->bindFakeVerifier(array_merge($this->validClaims, ['exp' => time() - 60]));

        $this->postGoogle()->assertStatus(401);
    }

    public function test_missing_email_or_sub_returns_401(): void
    {
        $this->bindFakeVerifier(array_merge($this->validClaims, ['sub' => '']));

        $this->postGoogle()->assertStatus(401);
    }

    public function test_verifier_failure_returns_401_and_never_calls_tokeninfo(): void
    {
        Http::fake();
        $this->bindFakeVerifier([], new \RuntimeException('signature failed'));

        $this->postGoogle()
            ->assertStatus(401)
            ->assertJson([
                'ok' => false,
                'message' => 'Invalid or unverified Google token.',
            ]);

        // The tokeninfo fallback is gone: a verifier failure is final.
        Http::assertNothingSent();
    }

    public function test_empty_client_ids_returns_503(): void
    {
        config(['services.google.client_ids' => '']);
        $this->bindFakeVerifier($this->validClaims);

        $this->postGoogle()
            ->assertStatus(503)
            ->assertJson([
                'ok' => false,
                'message' => 'Google sign-in is not configured.',
            ]);
    }

    public function test_new_buyer_registers_pending_and_links_the_google_sub(): void
    {
        $this->bindFakeVerifier($this->validClaims);

        $this->postGoogle('fake-token', $this->buyerPayload())
            ->assertStatus(201)
            ->assertJson([
                'ok' => true,
                'role' => 'buyer',
                'email' => 'user@gmail.com',
                'registration_status' => 'pending_approval',
            ])
            ->assertJsonStructure(['token']);

        $this->assertDatabaseHas('buyers', [
            'email' => 'user@gmail.com',
            'google_sub' => 'google-sub-123',
            'status' => 'pending_approval',
        ]);
    }

    public function test_new_rider_ends_pending_approval_without_a_token(): void
    {
        $this->bindFakeVerifier($this->validClaims);

        $response = $this->postGoogle('fake-token', $this->riderPayload())
            ->assertStatus(201)
            ->assertJson([
                'ok' => true,
                'role' => 'courier',
                'email' => 'user@gmail.com',
                'registration_status' => 'pending_approval',
            ]);

        $this->assertArrayNotHasKey('token', $response->json());

        $this->assertDatabaseHas('logistics_providers', [
            'email' => 'user@gmail.com',
            'google_sub' => 'google-sub-123',
            'plate_number' => 'VAN-8888',
            'status' => 'pending_approval',
        ]);

        $this->assertDatabaseMissing('logistics_providers', [
            'email' => 'user@gmail.com',
            'status' => 'approved',
        ]);
    }

    public function test_existing_approved_buyer_signs_in_by_google_sub(): void
    {
        Buyer::factory()->create([
            'email' => 'user@gmail.com',
            'google_sub' => 'google-sub-123',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->bindFakeVerifier($this->validClaims);

        $response = $this->postGoogle()
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'role' => 'buyer',
                'email' => 'user@gmail.com',
                'registration_status' => 'approved',
            ]);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_existing_approved_courier_signs_in_with_the_real_role(): void
    {
        LogisticsProvider::factory()->create([
            'email' => 'user@gmail.com',
            'google_sub' => 'google-sub-123',
            'status' => LogisticsProviderStatus::Approved,
            'email_verified_at' => now(),
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'COU-1234',
        ]);

        $this->bindFakeVerifier($this->validClaims);

        $response = $this->postGoogle()
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'role' => 'courier',
                'email' => 'user@gmail.com',
                'registration_status' => 'approved',
                'vehicle_type' => 'Motorcycle',
                'plate_number' => 'COU-1234',
            ]);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_verified_local_account_is_linked_on_first_google_sign_in(): void
    {
        $buyer = Buyer::factory()->create([
            'email' => 'user@gmail.com',
            'google_sub' => null,
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);

        $this->bindFakeVerifier($this->validClaims);

        $this->postGoogle()
            ->assertOk()
            ->assertJson(['ok' => true, 'role' => 'buyer']);

        $this->assertSame('google-sub-123', $buyer->fresh()->google_sub);
    }

    public function test_unverified_local_account_conflict_returns_409_without_linking(): void
    {
        $buyer = Buyer::factory()->create([
            'email' => 'user@gmail.com',
            'google_sub' => null,
            'status' => 'pending_verification',
            'email_verified_at' => null,
        ]);

        $this->bindFakeVerifier($this->validClaims);

        $this->postGoogle()
            ->assertStatus(409)
            ->assertJson([
                'ok' => false,
                'code' => 'EMAIL_UNVERIFIED',
                'message' => 'Please verify your email address before linking with Google.',
            ]);

        $this->assertNull($buyer->fresh()->google_sub);
    }

    public function test_pending_rider_cannot_obtain_a_token(): void
    {
        LogisticsProvider::factory()->pendingApproval()->create([
            'email' => 'user@gmail.com',
            'google_sub' => 'google-sub-123',
        ]);

        $this->bindFakeVerifier($this->validClaims);

        $response = $this->postGoogle()
            ->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'PENDING_APPROVAL',
                'registration_status' => 'pending_approval',
            ]);

        $this->assertArrayNotHasKey('token', $response->json());
    }

    public function test_rejected_rider_cannot_obtain_a_token(): void
    {
        LogisticsProvider::factory()->rejected()->create([
            'email' => 'user@gmail.com',
            'google_sub' => 'google-sub-123',
        ]);

        $this->bindFakeVerifier($this->validClaims);

        $this->postGoogle()
            ->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'ACCOUNT_REJECTED',
                'registration_status' => 'rejected',
            ])
            ->assertJsonMissingPath('token');
    }

    public function test_first_time_identity_without_a_role_is_asked_to_choose(): void
    {
        $this->bindFakeVerifier($this->validClaims);

        $this->postGoogle()
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'needs_role' => true,
                'email' => 'user@gmail.com',
                'name' => 'Test User',
            ]);
    }

    public function test_admin_is_never_obtainable_from_google_sign_in(): void
    {
        $this->bindFakeVerifier($this->validClaims);

        $this->postGoogle('fake-token', [
            'role' => 'admin',
            'first_name' => 'Bad',
            'last_name' => 'Actor',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('admins', ['email' => 'user@gmail.com']);
    }

    public function test_v1_google_route_uses_the_same_verifier(): void
    {
        $this->bindFakeVerifier($this->validClaims);

        $this->postJson(route('api.v1.auth.google'), ['id_token' => 'fake-token'])
            ->assertOk()
            ->assertJson(['ok' => true, 'needs_role' => true]);
    }
}
