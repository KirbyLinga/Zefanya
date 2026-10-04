<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use App\Notifications\Buyer\BuyerVerifyOtp;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The mobile app's door into buyer registration: `POST /api/register/buyer`.
 *
 * What these tests are for, beyond "it returns 201": the app has no session and
 * no CSRF token, so this route is the whole of what it can do, and three
 * properties matter more than the happy path —
 *
 *  1. **It is the same registration as the web one.** Same `FormRequest`, same
 *     `Buyer::createFromRegistration()`, so signing up on a phone and in a
 *     browser must produce identical rows under identical rules.
 *  2. **The handle it returns is usable.** The app stores `verification_id`
 *     opaquely and sends it back to the confirm route; a handle that were not
 *     the id that route is keyed on would be a dead end nobody could see from
 *     the response alone. One test below closes that loop for real.
 *  3. **The code is a secret.** It goes to the mail and nowhere else — not into
 *     the response body.
 *
 * Every request sets `Accept: application/json`, which is what the app sends and
 * what makes a validation failure a 422 rather than a redirect.
 */
class BuyerRegistrationApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function payload(array $overrides = []): array
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

    /** `post()` rather than `postJson()`: the body is multipart, as the app's is. */
    private function register(array $payload): TestResponse
    {
        return $this->post(
            route('api.register.buyer.store'),
            $payload,
            ['Accept' => 'application/json'],
        );
    }

    /** The raw 6-digit code, read out of the notification that carried it. */
    private function sentCode(Buyer $buyer): string
    {
        $code = null;

        Notification::assertSentTo(
            $buyer,
            BuyerVerifyOtp::class,
            function (BuyerVerifyOtp $notification) use (&$code) {
                $code = $notification->code;

                return true;
            },
        );

        return $code;
    }

    public function test_it_lands_on_the_email_gate_and_returns_a_handle(): void
    {
        Storage::fake('local');
        Notification::fake();

        $response = $this->register($this->payload());

        $response->assertStatus(201)
            ->assertJson([
                'ok' => true,
                'registration_status' => 'pending_verification',
            ])
            ->assertJsonStructure(['ok', 'verification_id', 'email', 'registration_status']);

        $buyer = Buyer::sole();
        $this->assertSame('pending_verification', $buyer->status);
        $this->assertNull($buyer->email_verified_at);
    }

    public function test_the_handle_is_the_id_the_confirm_route_is_keyed_on(): void
    {
        Storage::fake('local');
        Notification::fake();

        $handle = $this->register($this->payload())->json('verification_id');
        $buyer = Buyer::sole();

        $this->assertSame((string) $buyer->id, $handle);

        // The loop the app actually walks: the handle it stored, plus the code
        // that arrived by mail, against the route it built from nothing.
        $this->postJson(
            route('api.register.buyer.verify-otp', $handle),
            ['otp' => $this->sentCode($buyer)],
        )->assertOk();

        $this->assertSame('pending_approval', $buyer->fresh()->status);
    }

    public function test_the_raw_code_is_never_in_the_response(): void
    {
        Storage::fake('local');
        Notification::fake();

        $response = $this->register($this->payload());
        $code = $this->sentCode(Buyer::sole());

        $this->assertStringNotContainsString($code, $response->getContent());
    }

    public function test_the_password_is_stored_hashed(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->register($this->payload());

        $buyer = Buyer::sole();
        $this->assertNotSame('Password1!', $buyer->password);
        $this->assertTrue(Hash::check('Password1!', $buyer->password));
    }

    public function test_the_id_upload_is_stored_on_the_private_disk(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->register($this->payload());

        $path = Buyer::sole()->upload_id_path;
        $this->assertStringStartsWith('ids/', $path);
        Storage::disk('local')->assertExists($path);
    }

    // ── The rules are the web form's rules, because the request class is ──────

    public function test_missing_street_is_refused(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->register($this->payload(['street' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('street');

        $this->assertSame(0, Buyer::count());
    }

    public function test_a_short_password_is_refused(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->register($this->payload([
            'password' => 'short1!',
            'password_confirmation' => 'short1!',
        ]))->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertSame(0, Buyer::count());
    }

    public function test_a_mismatched_confirmation_is_refused(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->register($this->payload(['password_confirmation' => 'Different1!']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');

        $this->assertSame(0, Buyer::count());
    }

    public function test_a_malformed_ph_mobile_number_is_refused(): void
    {
        Storage::fake('local');
        Notification::fake();

        $this->register($this->payload(['contact_no' => '0917123456']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('contact_no');
    }

    public function test_an_email_that_already_has_an_account_is_refused(): void
    {
        Storage::fake('local');
        Notification::fake();

        $email = 'taken'.uniqid().'@example.com';
        Buyer::factory()->create(['email' => $email]);

        $this->register($this->payload(['email' => $email]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame(1, Buyer::count());
    }
}
