# Zefanya Laravel backend - the tests that prove the API

Run: cd web; php -d extension=pdo_sqlite vendor/bin/phpunit tests/Feature/Api tests/Feature/Services

## Service tests - the placing rules, with no controller in the path

### web/tests\Feature\Services\OrderPlacerTest.php  (233 lines)

```php
<?php

namespace Tests\Feature\Services;

use App\Enums\OrderStatus;
use App\Exceptions\CheckoutException;
use App\Models\Buyer\Buyer;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Models\Voucher;
use App\Services\OrderPlacer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The placing rules, on their own: no controller, no session, no view.
 *
 * These rules used to live inside the web's `CheckoutController`, where the only
 * test that reached them went through a POST and then a *rendered* confirmation
 * page — which this repo cannot render, because the frontend is not built here, so
 * the test died on the Vite manifest before asserting anything about the rows it
 * had just written. The rules were effectively untested, and the app's door could
 * not reach them at all.
 *
 * So this is the service's own test: lines in, rows out.
 */
class OrderPlacerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function seller(string $name): Seller
    {
        return Seller::factory()->create([
            'business_name' => $name,
            'status' => 'approved',
        ]);
    }

    private function product(
        Seller $seller,
        float $price = 1250.75,
        int $stock = 10,
        string $name = 'Handwoven Rattan Tote',
    ): Product {
        return Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => $name,
            'price' => $price,
            'stock_quantity' => $stock,
            'status' => 'active',
        ]);
    }

    public function test_it_writes_the_order_its_parcel_its_line_its_payment_and_the_ledger(): void
    {
        $seller = $this->seller('Loom & Larder');
        $product = $this->product($seller, price: 1250.75, stock: 10);
        $buyer = Buyer::factory()->create();

        $order = app(OrderPlacer::class)->place($buyer, [
            ['product_id' => $product->id, 'quantity' => 2],
        ]);

        // Centavos, from the server's own price: 1250.75 × 2.
        $this->assertSame(250150, $order->subtotal_minor);
        $this->assertSame(0, $order->discount_minor);
        $this->assertSame(250150, $order->total_minor);
        $this->assertSame(OrderStatus::Placed, $order->status);
        $this->assertStringStartsWith('ORD-', $order->reference);

        $parcel = SellerOrder::query()->sole();
        $this->assertSame($order->id, $parcel->order_id);
        $this->assertSame($seller->id, $parcel->seller_id);
        $this->assertSame(250150, $parcel->subtotal_minor);

        $line = OrderItem::query()->sole();
        $this->assertSame($parcel->id, $line->seller_order_id);
        $this->assertSame($product->id, $line->product_id);
        $this->assertSame('Handwoven Rattan Tote', $line->product_name);
        $this->assertSame(2, $line->quantity);
        $this->assertSame(125075, $line->price_minor);

        // COD and pending, for the parcel's full subtotal when no voucher was given.
        $payment = Payment::query()->sole();
        $this->assertSame($parcel->id, $payment->seller_order_id);
        $this->assertSame(250150, $payment->amount_minor);

        // Stock moved, and the append-only ledger says so in the same transaction.
        $this->assertSame(8, $product->fresh()->stock_quantity);

        $ledger = InventoryTransaction::query()->sole();
        $this->assertSame($product->id, $ledger->product_id);
        $this->assertSame($parcel->id, $ledger->seller_order_id);
        $this->assertSame(10, $ledger->quantity_before);
        $this->assertSame(8, $ledger->quantity_after);
        $this->assertSame(-2, $ledger->delta);
        $this->assertSame('checkout', $ledger->reason);
    }

    public function test_a_two_shop_checkout_splits_the_discount_so_the_payments_sum_to_the_total(): void
    {
        $loom = $this->seller('Loom & Larder');
        $tahanan = $this->seller('Tahanan Ceramics');

        $tote = $this->product($loom, price: 1000.00);
        $bowls = $this->product($tahanan, price: 500.00, name: 'Stoneware Rice Bowl Set');

        // ₱200 off a ₱1,500 basket: not a clean division, so the largest-remainder
        // rule is doing the work rather than a lucky ratio.
        $voucher = Voucher::factory()->fixed(20000)->create(['code' => 'ZEFANYANEW']);

        $order = app(OrderPlacer::class)->place(
            Buyer::factory()->create(),
            [
                ['product_id' => $tote->id, 'quantity' => 1],
                ['product_id' => $bowls->id, 'quantity' => 1],
            ],
            $voucher,
        );

        $this->assertSame(150000, $order->subtotal_minor);
        $this->assertSame(20000, $order->discount_minor);
        $this->assertSame(130000, $order->total_minor);

        // One parcel per shop, each with its own subtotal rather than the order's.
        $this->assertSame(
            [100000, 50000],
            SellerOrder::query()->orderBy('id')->pluck('subtotal_minor')->all(),
        );

        // And the two COD payments sum *exactly* to the discounted total — the
        // reason the allocation is proportional in the first place.
        $payments = Payment::query()->orderBy('id')->pluck('amount_minor')->all();

        $this->assertCount(2, $payments);
        $this->assertSame(130000, array_sum($payments));
    }

    public function test_a_line_with_no_stock_rolls_the_whole_order_back(): void
    {
        $product = $this->product($this->seller('Loom & Larder'), stock: 1);

        try {
            app(OrderPlacer::class)->place(Buyer::factory()->create(), [
                ['product_id' => $product->id, 'quantity' => 2],
            ]);

            $this->fail('A line with no stock must not be placeable.');
        } catch (CheckoutException) {
            // Expected: the authoritative gate runs under the lock.
        }

        // Nothing partial — no order, no parcel, no payment, no ledger row, and the
        // stock that was there is still there.
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, SellerOrder::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, InventoryTransaction::query()->count());
        $this->assertSame(1, $product->fresh()->stock_quantity);
    }

    public function test_a_product_that_is_no_longer_on_sale_is_refused_under_the_lock(): void
    {
        $draft = $this->product($this->seller('Loom & Larder'), name: 'Prototype Weave Sample');
        $draft->forceFill(['status' => 'inactive'])->save();

        $this->expectException(CheckoutException::class);

        app(OrderPlacer::class)->place(Buyer::factory()->create(), [
            ['product_id' => $draft->id, 'quantity' => 1],
        ]);
    }

    public function test_a_product_that_does_not_exist_is_refused_too(): void
    {
        // No error from a missing row: a line naming a product nobody has is
        // "not buyable", which is the same answer the cart page gives.
        $this->expectException(CheckoutException::class);

        app(OrderPlacer::class)->place(Buyer::factory()->create(), [
            ['product_id' => 999999, 'quantity' => 1],
        ]);
    }

    public function test_the_shipping_address_is_a_snapshot_of_the_buyer_at_order_time(): void
    {
        $product = $this->product($this->seller('Loom & Larder'));

        $buyer = Buyer::factory()->create([
            'first_name' => 'Marites',
            'last_name' => 'Santos',
            'contact_no' => '09171234567',
            'house_number' => '42',
            'street' => 'Maginhawa Street',
            'barangay_name' => 'Pinyahan',
            'municipality_name' => 'Quezon City',
            'province_name' => 'Metro Manila',
        ]);

        $order = app(OrderPlacer::class)->place($buyer, [
            ['product_id' => $product->id, 'quantity' => 1],
        ]);

        $address = $order->shipping_address;

        $this->assertSame('Marites Santos', $address['name']);
        $this->assertSame('09171234567', $address['phone']);
        $this->assertSame(
            '42, Maginhawa Street, Pinyahan, Quezon City, Metro Manila',
            $address['full'],
        );

        // A snapshot, not a link: editing the profile afterwards must not rewrite
        // what was shipped.
        $buyer->forceFill(['street' => 'Somewhere Else'])->save();

        $this->assertStringContainsString('Maginhawa', $order->fresh()->shipping_address['full']);
    }

    public function test_an_empty_line_list_is_an_error_rather_than_an_empty_order(): void
    {
        // A zero-line order would be a row nobody can fulfil — and an order number
        // for nothing.
        $this->expectException(CheckoutException::class);

        app(OrderPlacer::class)->place(Buyer::factory()->create(), []);
    }
}
```

## API tests, including the seeder tests

### web/tests\Feature\Api\BuyerLoginApiTest.php  (234 lines)

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Signing in from the app: `POST /api/login/buyer`, and the authenticated routes
 * that exist so a token has something to prove itself against.
 *
 * The property under test throughout is that a *valid token is not the same as a
 * usable account*. The app's own `login()` returns a result object for exactly
 * this reason, and these tests pin the server half of that contract: every gate
 * has its own `code` and `registration_status`, a rejected account is never
 * described as pending, and no token is issued to an account that cannot use one.
 */
class BuyerLoginApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** The factory's default is an approved buyer with the password `password`. */
    private function approvedBuyer(array $overrides = []): Buyer
    {
        return Buyer::factory()->create($overrides);
    }

    private function login(string $email, string $password = 'password')
    {
        return $this->postJson(
            route('api.login.buyer'),
            ['email' => $email, 'password' => $password],
            ['Accept' => 'application/json'],
        );
    }

    // ── The account that is through both gates ───────────────────────────────

    public function test_an_approved_buyer_gets_a_token_and_an_identity(): void
    {
        $buyer = $this->approvedBuyer();

        $response = $this->login($buyer->email);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'role' => 'buyer',
                'email' => $buyer->email,
                'registration_status' => 'approved',
            ])
            ->assertJsonStructure(['ok', 'token', 'role', 'name', 'email', 'registration_status']);

        $this->assertNotEmpty($response->json('token'));

        // The token is only worth issuing if it opens something, so the test
        // follows it rather than reading it.
        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson(route('api.me'))
            ->assertOk()
            ->assertJson(['ok' => true, 'role' => 'buyer', 'email' => $buyer->email]);
    }

    public function test_credentials_are_checked_against_the_stored_hash(): void
    {
        $buyer = $this->approvedBuyer(['password' => Hash::make('Correct1!')]);

        $this->login($buyer->email, 'Correct1!')->assertOk();
        $this->login($buyer->email, 'Wrong1!')->assertStatus(422);
    }

    // ── The gates ────────────────────────────────────────────────────────────

    public function test_the_email_gate_comes_back_with_a_handle_and_no_token(): void
    {
        $buyer = $this->approvedBuyer([
            'status' => 'pending_verification',
            'email_verified_at' => null,
            'approved_at' => null,
        ]);

        $this->login($buyer->email)
            ->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'PENDING_VERIFICATION',
                'registration_status' => 'pending_verification',
                // Without this the app could not offer the confirm screen to
                // someone who closed the app before entering their code.
                'verification_id' => (string) $buyer->id,
            ]);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_the_admin_gate_is_its_own_code_and_has_no_handle(): void
    {
        $buyer = $this->approvedBuyer([
            'status' => 'pending_approval',
            'approved_at' => null,
        ]);

        $this->login($buyer->email)
            ->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'PENDING_APPROVAL',
                'registration_status' => 'pending_approval',
            ])
            ->assertJsonMissingPath('verification_id');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_rejected_account_is_never_described_as_pending(): void
    {
        $buyer = $this->approvedBuyer(['status' => 'rejected', 'approved_at' => null]);

        $response = $this->login($buyer->email);

        $response->assertStatus(403)
            ->assertJson([
                'ok' => false,
                'code' => 'ACCOUNT_REJECTED',
                'registration_status' => 'rejected',
            ]);

        // The bug this guards: `rejected` is not past email verification, so a
        // gate check ordered the other way round would tell someone their
        // application is still being reviewed after it was turned down.
        $this->assertStringNotContainsString(
            'PENDING_',
            (string) $response->getContent(),
        );
    }

    // ── Credentials ──────────────────────────────────────────────────────────

    public function test_a_wrong_password_is_a_422_on_the_email_field(): void
    {
        $buyer = $this->approvedBuyer();

        $this->login($buyer->email, 'Wrong1!')
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        // No token, and no attempt to hand one out anyway.
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_unknown_email_gets_the_same_sentence_as_a_wrong_password(): void
    {
        $buyer = $this->approvedBuyer();

        $wrongPassword = $this->login($buyer->email, 'Wrong1!');
        $unknownEmail = $this->login('nobody'.uniqid().'@example.com');

        $this->assertSame(422, $unknownEmail->status());
        $this->assertSame(
            $wrongPassword->json('errors.email.0'),
            $unknownEmail->json('errors.email.0'),
        );
    }

    // ── The authenticated routes ─────────────────────────────────────────────

    public function test_me_without_a_token_is_unauthorized(): void
    {
        $this->getJson(route('api.me'))->assertStatus(401);
    }

    public function test_me_reports_the_gate_when_the_account_moved_after_the_token_was_issued(): void
    {
        $buyer = $this->approvedBuyer();
        $token = $this->login($buyer->email)->json('token');

        // An account can end up behind a gate again — an admin withdrawing an
        // approval is exactly what `rejection_reason`/`approved_by` exist for —
        // and a token issued before that must not keep reading as signed in.
        $buyer->forceFill(['status' => 'pending_approval'])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('api.me'))
            ->assertStatus(403)
            ->assertJson(['code' => 'PENDING_APPROVAL']);
    }

    public function test_a_revoked_token_is_refused(): void
    {
        $buyer = $this->approvedBuyer();
        $token = $this->login($buyer->email)->json('token');

        // Deleted before any request in *this* test has resolved the guard, then
        // presented. This is the assertion the logout test below cannot make:
        // within one test method Laravel reuses the application, and Sanctum's
        // request guard caches the user it resolved, so a follow-up request can
        // look authenticated by the guard's memory rather than by the token.
        $buyer->tokens()->delete();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('api.me'))
            ->assertStatus(401);
    }

    public function test_logout_revokes_only_the_token_that_was_used(): void
    {
        $buyer = $this->approvedBuyer();

        $phone = $this->login($buyer->email)->json('token');
        $tablet = $this->login($buyer->email)->json('token');
        $this->assertDatabaseCount('personal_access_tokens', 2);

        $this->withHeader('Authorization', 'Bearer '.$phone)
            ->postJson(route('api.logout'))
            ->assertOk();

        // The row itself must be gone. `currentAccessToken()` returns Sanctum's
        // `TransientToken` on the session path, whose `delete()` is a no-op — so
        // this endpoint could answer 200 while revoking nothing, and the count
        // is what catches that.
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertNull(PersonalAccessToken::findToken($phone));
        $this->assertNotNull(PersonalAccessToken::findToken($tablet));

        // The other session is untouched: signing out of one device must not
        // sign the person out of the other.
        $this->withHeader('Authorization', 'Bearer '.$tablet)
            ->getJson(route('api.me'))
            ->assertOk();
    }
}
```

### web/tests\Feature\Api\BuyerOtpApiTest.php  (203 lines)

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Confirming the emailed code from the app: the two routes under
 * `/api/register/buyer/verify-otp/{buyer}`.
 *
 * The response shapes here are not arbitrary. The app renders the server's own
 * sentence in one inline line under the field, so:
 *
 *  - a refusal must carry `message` and must **not** carry an `errors` bag
 *    (a field bag would claim the server said *which* field was wrong when all
 *    it said was no), and
 *  - a success must say which gate the account is now at, because a phone has no
 *    redirect URL to follow.
 *
 * The last test in this file is the one that matters most structurally: it
 * checks the mobile route and the web modal agree about whether the gate is
 * open, which is the thing two copies of "does this code match" would silently
 * stop agreeing about.
 */
class BuyerOtpApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * A buyer sitting on the email gate, and the code that would open it.
     *
     * @return array{0: Buyer, 1: string}
     */
    private function awaitingVerification(array $overrides = []): array
    {
        $buyer = Buyer::factory()->create(array_merge([
            'status' => 'pending_verification',
            'email_verified_at' => null,
            'approved_at' => null,
        ], $overrides));

        return [$buyer, $buyer->issueEmailVerificationCode()];
    }

    private function confirm(Buyer $buyer, string $code)
    {
        return $this->postJson(
            route('api.register.buyer.verify-otp', $buyer->id),
            ['otp' => $code],
            ['Accept' => 'application/json'],
        );
    }

    // ── Opening the gate ─────────────────────────────────────────────────────

    public function test_the_right_code_moves_the_account_to_the_admin_gate(): void
    {
        [$buyer, $code] = $this->awaitingVerification();

        $this->confirm($buyer, $code)
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'registration_status' => 'pending_approval',
            ]);

        $buyer->refresh();
        $this->assertSame('pending_approval', $buyer->status);
        $this->assertNotNull($buyer->email_verified_at);
        // Cleared, so the code cannot be replayed by whoever else saw the email.
        $this->assertNull($buyer->email_verification_code);
    }

    public function test_a_confirmed_account_can_confirm_again_without_a_failure(): void
    {
        [$buyer, $code] = $this->awaitingVerification();
        $this->confirm($buyer, $code)->assertOk();

        // A double tap, or a resend whose mail arrived after the confirmation,
        // must not look like a failure to the person who just succeeded.
        $this->confirm($buyer, $code)
            ->assertOk()
            ->assertJson(['ok' => true, 'already_verified' => true]);
    }

    // ── Refusals, and the shape they must have ───────────────────────────────

    public function test_a_wrong_code_is_a_message_without_a_field_bag(): void
    {
        [$buyer, $code] = $this->awaitingVerification();
        $wrong = $code === '000000' ? '111111' : '000000';

        $response = $this->confirm($buyer, $wrong);

        $response->assertStatus(422)
            ->assertJson(['ok' => false])
            ->assertJsonStructure(['ok', 'message'])
            ->assertJsonMissingPath('errors')
            ->assertJsonMissingPath('expired');

        $this->assertSame('pending_verification', $buyer->fresh()->status);
    }

    public function test_an_expired_code_says_expired_and_changes_nothing(): void
    {
        [$buyer, $code] = $this->awaitingVerification();

        $this->travel(11)->minutes();

        $this->confirm($buyer, $code)
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'expired' => true])
            ->assertJsonMissingPath('errors');

        $this->assertSame('pending_verification', $buyer->fresh()->status);
    }

    public function test_the_field_is_validated_before_the_code_is_compared(): void
    {
        [$buyer, $code] = $this->awaitingVerification();

        // The app enforces this shape in the field itself, so a request like
        // this means a client that is not the app — and it must be answered by
        // validation (which names the field) rather than by spending an attempt
        // on a code that could not have been right.
        //
        // Note the shape difference from the refusals above: a validation
        // failure is Laravel's own `{message, errors}` with no `ok` key, while a
        // wrong code is this controller's `{ok: false, message}`. Both arrive at
        // the app as a 422 carrying a sentence, which is all the screen reads.
        $this->confirm($buyer, '12345')
            ->assertStatus(422)
            ->assertJsonValidationErrors('otp')
            ->assertJsonMissingPath('ok');

        $this->confirm($buyer, 'abcdef')
            ->assertStatus(422)
            ->assertJsonValidationErrors('otp');
    }

    // ── Resending ────────────────────────────────────────────────────────────

    public function test_resend_issues_a_new_code_and_retires_the_old_one(): void
    {
        \Illuminate\Support\Facades\Notification::fake();
        [$buyer, $oldCode] = $this->awaitingVerification();

        $newCode = null;
        $this->postJson(route('api.register.buyer.verify-otp.resend', $buyer->id))
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'A new code has been sent.']);

        \Illuminate\Support\Facades\Notification::assertSentTo(
            $buyer->fresh(),
            \App\Notifications\Buyer\BuyerVerifyOtp::class,
            function ($notification) use (&$newCode) {
                $newCode = $notification->code;

                return true;
            },
        );

        $this->assertNotSame($oldCode, $newCode);

        // Both halves matter: the code just sent works, and the one it replaced
        // does not. A resend that left the first code live would quietly double
        // the window an intercepted email stays useful for.
        if ($oldCode !== $newCode) {
            $this->confirm($buyer, $oldCode)->assertStatus(422);
        }
        $this->confirm($buyer, $newCode)->assertOk();
    }

    public function test_resend_is_refused_once_the_gate_is_open(): void
    {
        [$buyer, $code] = $this->awaitingVerification();
        $this->confirm($buyer, $code)->assertOk();

        $this->postJson(route('api.register.buyer.verify-otp.resend', $buyer->id))
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'This account is already verified.']);
    }

    // ── The two doors agree ──────────────────────────────────────────────────

    public function test_the_web_modal_sees_the_gate_the_api_opened(): void
    {
        [$buyer, $code] = $this->awaitingVerification();

        $this->confirm($buyer, $code)->assertOk();

        // The web modal posts to its own route with the same code. It must read
        // the same row as "already verified" — the failure this guards is two
        // controllers with two copies of "is this account past the gate", where
        // one offers a confirmation the other considers finished.
        $this->postJson(
            route('register.buyer.verify-otp', $buyer->id),
            ['otp' => $code],
        )->assertOk()->assertJson(['ok' => true, 'already_verified' => true]);
    }
}
```

### web/tests\Feature\Api\BuyerRegistrationApiTest.php  (221 lines)

```php
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
```

### web/tests\Feature\Api\CategoryApiTest.php  (64 lines)

```php
<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/categories` — the chips the app's category row renders.
 *
 * The list is seeded by its migration (fourteen curated rows, with a comment
 * saying not to build a CRUD for them), so these tests are mostly about *which*
 * list arrives and in what order, rather than about creating data.
 *
 * The interesting property is the shape of the answer to a question the app has
 * been carrying as an open one: its own mock catalogue lists four categories
 * ('All', 'Fashion', 'Home & Living', 'Beauty') and the server has fourteen whose
 * names do not overlap. A live build shows the server's fourteen — that is the
 * answer, and it means a live category chip can never be one the server has no
 * products for, because the names come from the same table the products point at.
 */
class CategoryApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_returns_the_curated_list_with_labels(): void
    {
        $response = $this->getJson(route('api.categories.index'));

        $response->assertOk()
            ->assertJsonStructure(['data' => [['id', 'label']]])
            ->assertJsonCount(14, 'data');

        $labels = collect($response->json('data'))->pluck('label');

        $this->assertContains('Home and Garden', $labels);
        $this->assertContains('Pet Supplies', $labels);
    }

    public function test_the_order_is_the_curations_own(): void
    {
        $labels = collect($this->getJson(route('api.categories.index'))->json('data'))
            ->pluck('label')
            ->all();

        // Ordered by id, which is the order the migration inserted them in — the
        // file calls itself a fixed curated list, so its order is the curation.
        // Alphabetical would be tidier and would throw that away.
        $this->assertSame('Books and Media', $labels[0]);
        $this->assertSame('Office and School Supplies', $labels[13]);
    }

    public function test_an_id_is_a_string_like_every_other_id_the_app_holds(): void
    {
        $id = $this->getJson(route('api.categories.index'))->json('data.0.id');

        $this->assertIsString($id);
    }

    public function test_it_needs_no_token(): void
    {
        $this->getJson(route('api.categories.index'))->assertOk();
    }
}
```

### web/tests\Feature\Api\DemoBuyerSeederTest.php  (85 lines)

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use Database\Seeders\DemoBuyerSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The demo buyer is a *login*, so the test that matters is the one that signs in.
 *
 * A seeded credential is only worth having if it works through the front door the
 * app uses — the real route, the real throttle, the real token — and only safe if
 * it cannot exist in production. Both are asserted here, along with the two
 * timestamps that are not fillable and would otherwise be silently null.
 */
class DemoBuyerSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_seeded_buyer_signs_in_through_the_api_and_the_token_works(): void
    {
        $this->seed(DemoBuyerSeeder::class);

        $login = $this->postJson(route('api.login.buyer'), [
            'email' => DemoBuyerSeeder::EMAIL,
            'password' => DemoBuyerSeeder::PASSWORD,
        ])->assertOk();

        $token = $login->json('token');
        $this->assertIsString($token);

        // Issued is not the same as usable: `/me` is the only route that proves the
        // token was accepted, and it answers with the buyer's own fields.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(route('api.me'))
            ->assertOk()
            ->assertJsonPath('email', DemoBuyerSeeder::EMAIL);
    }

    public function test_the_buyer_is_approved_and_verified_on_the_record_not_just_in_status(): void
    {
        $this->seed(DemoBuyerSeeder::class);

        $buyer = Buyer::query()->where('email', DemoBuyerSeeder::EMAIL)->sole();

        // `email_verified_at` and `approved_at` are not fillable, so a seeder that
        // passes them through `fill()` writes nulls — and the sign-in gate would
        // then send the person to a verification screen for a code nobody sent.
        $this->assertSame('approved', $buyer->status);
        $this->assertNotNull($buyer->email_verified_at);
        $this->assertNotNull($buyer->approved_at);
        $this->assertNull($buyer->email_verification_code);
    }

    public function test_seeding_twice_neither_duplicates_the_account_nor_resets_it(): void
    {
        $this->seed(DemoBuyerSeeder::class);

        // A password changed while testing is not this seeder's to undo.
        $buyer = Buyer::query()->where('email', DemoBuyerSeeder::EMAIL)->sole();
        $buyer->forceFill(['password' => 'changed-while-testing'])->save();
        $changed = $buyer->fresh()->password;

        $this->seed(DemoBuyerSeeder::class);

        $accounts = Buyer::query()->where('email', DemoBuyerSeeder::EMAIL)->get();

        $this->assertCount(1, $accounts);
        $this->assertSame($changed, $accounts->first()->password);
    }

    public function test_it_refuses_to_create_a_credential_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        // Called directly rather than through `$this->seed()`: `db:seed` refuses to
        // run in production without `--force`, and that would test the command
        // instead of the seeder's own guard.
        (new DemoBuyerSeeder)->run();

        $this->assertSame(0, Buyer::count());
    }
}
```

### web/tests\Feature\Api\DemoCatalogueSeederTest.php  (142 lines)

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Seller\Seller;
use Database\Seeders\DemoCatalogueSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * The demo catalogue has to satisfy the *endpoint's* rules, not merely exist.
 *
 * A seeder can look right and still produce a database the app cannot see: an
 * unapproved seller, a status typo'd to `draft`, a category label that matches no
 * row, a photo path that is not a URL. Each of those turns a filled database into
 * an empty-looking app, and each is asserted here rather than hoped for.
 */
class DemoCatalogueSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function catalogue(): array
    {
        $this->seed(DemoCatalogueSeeder::class);

        return $this->getJson(route('api.products.index'))->assertOk()->json('data');
    }

    public function test_the_demo_catalogue_is_visible_to_the_app(): void
    {
        $this->assertCount(DemoCatalogueSeeder::VISIBLE_PRODUCTS, $this->catalogue());
    }

    public function test_every_demo_product_has_a_category_a_shop_and_a_price(): void
    {
        foreach ($this->catalogue() as $row) {
            // A category label that matches no row silently becomes
            // "Uncategorised", so this is what catches a typo in the seeder.
            $this->assertNotSame('Uncategorised', $row['category'], $row['name'].' must resolve to a category row');
            $this->assertNotSame('', $row['seller']['name'], $row['name'].' must name its shop');
            $this->assertGreaterThan(0, $row['price_minor'], $row['name'].' must cost something');
        }
    }

    public function test_every_photo_is_a_url_the_app_can_fetch(): void
    {
        foreach ($this->catalogue() as $row) {
            $this->assertNotEmpty($row['images'], $row['name'].' needs a cover photo');

            foreach ($row['images'] as $url) {
                // `Image.network` cannot fetch a relative path, and this is the
                // assertion that would have caught Storage::url() answering
                // `/storage/...` for an uploaded photo.
                $this->assertMatchesRegularExpression(
                    '#^https?://#',
                    $url,
                    $row['name'].' hands the app a photo it cannot load: '.$url,
                );
            }
        }
    }

    public function test_at_least_one_product_carries_a_gallery(): void
    {
        $withSeveralPhotos = array_filter(
            $this->catalogue(),
            fn (array $row): bool => count($row['images']) > 1,
        );

        $this->assertNotEmpty(
            $withSeveralPhotos,
            'the gallery and the stacked showcase need a product with more than one photo',
        );
    }

    public function test_a_sold_out_product_is_listed_and_says_so(): void
    {
        $windChime = collect($this->catalogue())->firstWhere('name', 'Capiz Shell Wind Chime');

        $this->assertNotNull($windChime, 'a sold-out product must not vanish from the catalogue');
        $this->assertFalse($windChime['in_stock']);
    }

    public function test_the_rows_that_must_stay_hidden_do(): void
    {
        $names = collect($this->catalogue())->pluck('name');

        $this->assertNotContains('Prototype Weave Sample', $names, 'a draft product must not be listed');
        $this->assertNotContains('Uling Starter Bag', $names, "an unapproved seller's product must not be listed");

        // The single read agrees with the list, and 404s rather than 403s: the app
        // is told "this was never here", which is what a shop window says about a
        // draft, and it cannot be used to probe which ids exist.
        $draft = Product::query()->where('name', 'Prototype Weave Sample')->sole();

        $this->getJson(route('api.products.show', $draft))->assertNotFound();
    }

    public function test_the_demo_shops_are_verified_and_approved_on_the_record_not_just_in_status(): void
    {
        $this->catalogue();

        // `email_verified_at` and `approved_at` are not fillable, so a seeder that
        // passes them through `updateOrCreate` silently writes nulls: the shops
        // would look approved to the catalogue query and unverified to every other
        // screen in the app.
        $approved = Seller::query()->where('status', 'approved')->get();

        $this->assertCount(5, $approved);

        foreach ($approved as $seller) {
            $this->assertNotNull($seller->email_verified_at, $seller->business_name.' is approved but never verified its email');
            $this->assertNotNull($seller->approved_at, $seller->business_name.' is approved with no approval timestamp');
        }

        // And the one shop that is still waiting keeps its own story: verified,
        // not approved.
        $pending = Seller::query()->where('business_name', 'Bagong Uling Supplies')->sole();

        $this->assertSame('pending_approval', $pending->status);
        $this->assertNotNull($pending->email_verified_at);
        $this->assertNull($pending->approved_at);
    }

    public function test_seeding_twice_changes_nothing(): void
    {
        $this->catalogue();

        $products = Product::count();
        $images = ProductImage::count();
        $sellers = Seller::count();

        $this->seed(DemoCatalogueSeeder::class);

        $this->assertSame($products, Product::count(), 're-seeding duplicated products');
        $this->assertSame($images, ProductImage::count(), 're-seeding duplicated photos');
        $this->assertSame($sellers, Seller::count(), 're-seeding duplicated shops');
    }
}
```

### web/tests\Feature\Api\DemoOrderSeederTest.php  (125 lines)

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\SellerOrder;
use Database\Seeders\DemoBuyerSeeder;
use Database\Seeders\DemoCatalogueSeeder;
use Database\Seeders\DemoOrderSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The demo history has to be a *real* history, and reach the app the way one does.
 *
 * Seeding order rows by hand would have been shorter, and it is exactly what must
 * not happen: the demo data would then be the only thing in the project that ever
 * exercised a hand-built order shape, and the day the checkout changed, the seeded
 * rows would quietly be the wrong shape while the Orders tab still looked fine.
 * These tests assert the properties that prove the rows came from the checkout
 * itself — real stock movements, an append-only ledger, and a buyer who can read
 * them back through the same endpoint the app calls.
 */
class DemoOrderSeederTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_the_demo_buyer_ends_up_with_parcels_the_api_lists(): void
    {
        $this->seed(DemoOrderSeeder::class);

        $buyer = Buyer::query()->where('email', DemoBuyerSeeder::EMAIL)->sole();

        Sanctum::actingAs($buyer);

        $response = $this->getJson(route('api.orders.index'))->assertOk();

        // Two checkouts, and the first one deliberately spans two shops — which is
        // the one thing about a history that a single-shop fixture cannot show.
        $this->assertSame(2, Order::query()->where('buyer_id', $buyer->id)->count());

        $parcels = SellerOrder::query()
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->where('orders.buyer_id', $buyer->id)
            ->get();

        $this->assertCount(3, $parcels, 'one order across two shops plus one single-shop order');

        $this->assertCount(3, $response->json('data'));

        // Everything a card needs, present: a shop to name, an order number to read
        // back, and the product ids the app's joins depend on.
        foreach ($response->json('data') as $row) {
            $this->assertNotSame('', $row['shop']);
            $this->assertStringStartsWith('ORD-', $row['reference']);
            $this->assertSame('PLACED', $row['status']);
            $this->assertNotEmpty($row['product_ids']);
            $this->assertNotEmpty($row['items']);
        }
    }

    public function test_it_places_the_orders_through_the_checkout_rather_than_inserting_rows(): void
    {
        // The catalogue on its own, so the stock it seeds is the "before".
        $this->seed(DemoCatalogueSeeder::class);

        $before = (int) Product::query()->sum('stock_quantity');

        $this->seed(DemoOrderSeeder::class);

        $orderedUnits = (int) SellerOrder::query()
            ->join('orders', 'orders.id', '=', 'seller_orders.order_id')
            ->join('order_items', 'order_items.seller_order_id', '=', 'seller_orders.id')
            ->sum('order_items.quantity');

        $this->assertGreaterThan(0, $orderedUnits);

        // Stock really moved, by exactly the units ordered: the service's locked
        // decrement ran, rather than a row being written beside it.
        $this->assertSame($before - $orderedUnits, (int) Product::query()->sum('stock_quantity'));

        // And the append-only ledger says so, which hand-inserted rows would not.
        $ledger = InventoryTransaction::query()->where('reason', 'checkout')->get();

        $this->assertCount($orderedUnits, $ledger);
        $this->assertSame($orderedUnits, (int) $ledger->sum(fn ($row): int => abs($row->delta)));
    }

    public function test_seeding_twice_does_not_stack_a_second_demo_history(): void
    {
        $this->seed(DemoOrderSeeder::class);

        $orders = Order::query()->count();
        $stock = (int) Product::query()->sum('stock_quantity');

        $this->seed(DemoOrderSeeder::class);

        // Re-runnable, like every seeder beside it: a second pass neither mints
        // another history nor consumes more stock.
        $this->assertSame($orders, Order::query()->count());
        $this->assertSame($stock, (int) Product::query()->sum('stock_quantity'));
    }

    public function test_it_refuses_to_consume_stock_in_production(): void
    {
        $this->seed(DemoCatalogueSeeder::class);
        $this->seed(DemoBuyerSeeder::class);

        $stock = (int) Product::query()->sum('stock_quantity');

        $this->app->detectEnvironment(fn (): string => 'production');

        // Called directly rather than through `$this->seed()`: `db:seed` refuses to
        // run in production without `--force`, which would test the command instead
        // of the seeder's own guard.
        (new DemoOrderSeeder)->run();

        $this->assertSame(0, Order::query()->count());
        $this->assertSame($stock, (int) Product::query()->sum('stock_quantity'));
    }
}
```

### web/tests\Feature\Api\OrderApiTest.php  (184 lines)

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `GET /api/orders` — the buyer's own parcels.
 *
 * The first authenticated *read* on this API, so two things are asserted that the
 * public routes never needed: that a stranger with no token gets nothing, and that
 * one buyer's token cannot reach another buyer's history. The rest is the shape the
 * app's `Order` reads, plus the two facts that are easy to get quietly wrong —
 * centavos, and the product ids the app's joins depend on.
 *
 * The rows are built directly rather than through factories, because no order
 * factories exist and inventing them would mean guessing at money rules the checkout
 * owns. What is asserted here is what the endpoint *reads*, not how an order is
 * written.
 */
class OrderApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * A buyer with one order from one shop, and the rows that make it up.
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $lines  name, quantity, price in centavos
     */
    private function orderFor(Buyer $buyer, string $shopName = 'Loom & Larder', array $lines = [['Handwoven Rattan Tote', 1, 125075]]): Order
    {
        $seller = Seller::factory()->create([
            'business_name' => $shopName,
            'status' => 'approved',
        ]);

        $subtotal = array_sum(array_map(fn (array $line): int => $line[1] * $line[2], $lines));

        $order = Order::query()->create([
            'buyer_id' => $buyer->id,
            'reference' => Order::generateUniqueReference(),
            'shipping_address' => ['full' => '12-A Sanciangko Street, Lahug, Cebu City'],
            'subtotal_minor' => $subtotal,
            'discount_minor' => 0,
            'total_minor' => $subtotal,
        ]);

        $sellerOrder = SellerOrder::query()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'subtotal_minor' => $subtotal,
        ]);

        foreach ($lines as [$name, $quantity, $priceMinor]) {
            OrderItem::query()->create([
                'seller_order_id' => $sellerOrder->id,
                'product_id' => Product::factory()->create([
                    'seller_id' => $seller->id,
                    'name' => $name,
                ])->id,
                'product_name' => $name,
                'quantity' => $quantity,
                'price_minor' => $priceMinor,
            ]);
        }

        return $order;
    }

    public function test_it_requires_a_token(): void
    {
        $this->getJson(route('api.orders.index'))->assertUnauthorized();
    }

    public function test_it_lists_only_the_token_holders_own_parcels(): void
    {
        $me = Buyer::factory()->create();
        $someoneElse = Buyer::factory()->create();

        $this->orderFor($me, 'Loom & Larder');
        $this->orderFor($someoneElse, 'Tahanan Ceramics');

        Sanctum::actingAs($me);

        $this->getJson(route('api.orders.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.shop', 'Loom & Larder');
    }

    public function test_the_shape_the_apps_order_model_reads(): void
    {
        $me = Buyer::factory()->create();

        $this->orderFor($me, 'Loom & Larder', [
            ['Handwoven Rattan Tote', 1, 125075],
            ['Kamagong Stool', 2, 380000],
        ]);

        Sanctum::actingAs($me);

        $response = $this->getJson(route('api.orders.index'))->assertOk();

        $response->assertJsonStructure([
            'data' => [
                [
                    'id', 'reference', 'status', 'shop', 'subtotal_minor',
                    'items' => [['name', 'quantity', 'price_minor', 'product_id']],
                    'product_ids',
                ],
            ],
        ]);

        // A string id, like every other id the app reads: its order list, its
        // tracker and its review joins key on strings that came from the mock.
        $this->assertIsString($response->json('data.0.id'));
        $this->assertStringStartsWith('ORD-', $response->json('data.0.reference'));

        // The server's own status vocabulary, not the app's four display stages —
        // thirteen is not four, and the app is the side that chose to simplify.
        $this->assertSame('PLACED', $response->json('data.0.status'));

        // Centavos, added up the way the columns hold them.
        $this->assertSame(885075, $response->json('data.0.subtotal_minor'));
        $this->assertCount(2, $response->json('data.0.items'));
        $this->assertSame('Handwoven Rattan Tote', $response->json('data.0.items.0.name'));
        $this->assertSame(1, $response->json('data.0.items.0.quantity'));
        $this->assertSame(125075, $response->json('data.0.items.0.price_minor'));
        $this->assertSame(2, $response->json('data.0.items.1.quantity'));
        $this->assertCount(2, $response->json('data.0.product_ids'));
    }

    public function test_a_removed_product_keeps_its_line_and_drops_its_id(): void
    {
        $me = Buyer::factory()->create();
        $this->orderFor($me, 'Loom & Larder', [['Capiz Shell Wind Chime', 1, 68000]]);

        // `order_items.product_id` is nullOnDelete on purpose: the line is the audit
        // record of what was bought, and the product may not exist any more.
        OrderItem::query()->sole()->product->forceDelete();

        Sanctum::actingAs($me);

        $response = $this->getJson(route('api.orders.index'))->assertOk();

        $this->assertSame('Capiz Shell Wind Chime', $response->json('data.0.items.0.name'));
        $this->assertNull($response->json('data.0.items.0.product_id'));
        // And no null in the id list: the app's `productIds` means "the catalogue
        // items this order contains", so a removed product is simply absent.
        $this->assertSame([], $response->json('data.0.product_ids'));
    }

    public function test_a_two_shop_checkout_is_two_parcels_kept_together_and_newest_first(): void
    {
        $me = Buyer::factory()->create();

        $this->orderFor($me, 'Hiraya Weaves');
        $this->travel(1)->minutes();
        $newer = $this->orderFor($me, 'Kape ni Lolo');

        // The second parcel belongs to the newer checkout, so it sits beside its
        // sibling: ordering is by the parent order's timestamp, which keeps one
        // checkout's parcels together rather than interleaving them by their ids.
        SellerOrder::query()->create([
            'order_id' => $newer->id,
            'seller_id' => Seller::factory()->create(['business_name' => 'Alon Botanicals'])->id,
            'subtotal_minor' => 100,
        ]);

        Sanctum::actingAs($me);

        $shops = $this->getJson(route('api.orders.index'))->assertOk()->json('data.*.shop');

        $this->assertSame(['Kape ni Lolo', 'Alon Botanicals', 'Hiraya Weaves'], $shops);
    }
}
```

### web/tests\Feature\Api\PlaceOrderApiTest.php  (248 lines)

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Buyer\Buyer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `POST /api/orders` — the phone's basket, placed through the web's own rules.
 *
 * This is the first *write* on the API, and the first time the app's door reaches
 * money code, so the assertions are about the two things that could quietly be
 * wrong: who owns the order, and where the amounts came from.
 *
 * The amount assertions are the point of the endpoint. The app's cart lives on
 * the device, which makes the request a *proposal* — product ids and quantities —
 * and everything priced has to be re-derived on the server. So one test
 * deliberately posts a bargain (`price_minor: 1`, plus a line total and an order
 * subtotal for good measure) and asserts the order is still charged the
 * catalogue's price. A service that trusted any of them would be a way to buy
 * anything for a peso.
 *
 * The rules themselves — the lock, the centavos, the discount split, the rollback
 * — are `OrderPlacerTest`'s subject. What is checked here is that this route hands
 * the service its lines and its voucher correctly, answers in the shape the app
 * already parses, and gives a refusal the app can act on.
 */
class PlaceOrderApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function product(
        string $shopName = 'Loom & Larder',
        float $price = 1250.75,
        int $stock = 10,
        string $name = 'Handwoven Rattan Tote',
    ): Product {
        $seller = Seller::factory()->create([
            'business_name' => $shopName,
            'status' => 'approved',
        ]);

        return Product::factory()->create([
            'seller_id' => $seller->id,
            'name' => $name,
            'price' => $price,
            'stock_quantity' => $stock,
            'status' => 'active',
        ]);
    }

    public function test_it_requires_a_token(): void
    {
        $this->postJson(route('api.orders.store'), [
            'items' => [['product_id' => 1, 'quantity' => 1]],
        ])->assertUnauthorized();
    }

    public function test_it_places_the_order_and_answers_with_the_same_parcels_the_history_returns(): void
    {
        $me = Buyer::factory()->create();
        $product = $this->product();

        Sanctum::actingAs($me);

        $response = $this->postJson(route('api.orders.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertCreated();

        // The shape `GET /orders` sends, so the app parses one payload for placing
        // and for listing.
        $response->assertJsonStructure([
            'data' => [
                ['id', 'reference', 'status', 'shop', 'subtotal_minor', 'items', 'product_ids'],
            ],
            'meta' => ['order_id', 'reference', 'subtotal_minor', 'discount_minor', 'total_minor'],
        ]);

        $this->assertSame('Loom & Larder', $response->json('data.0.shop'));
        $this->assertSame('PLACED', $response->json('data.0.status'));
        $this->assertSame(250150, $response->json('data.0.subtotal_minor'));
        $this->assertSame([(string) $product->id], $response->json('data.0.product_ids'));

        // Order-level money beside the parcels: a discount is split *across*
        // parcels, so only the order can report the total honestly.
        $this->assertSame(250150, $response->json('meta.subtotal_minor'));
        $this->assertSame(0, $response->json('meta.discount_minor'));
        $this->assertSame(250150, $response->json('meta.total_minor'));
        $this->assertSame($response->json('meta.reference'), $response->json('data.0.reference'));

        // And the rows exist, owned by the token holder — never by a body field.
        $order = Order::query()->sole();
        $this->assertSame($me->id, $order->buyer_id);
        $this->assertSame(250150, $order->total_minor);
        $this->assertSame(8, $product->fresh()->stock_quantity);
        $this->assertSame(1, SellerOrder::query()->count());
        $this->assertSame(1, OrderItem::query()->count());
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_the_request_cannot_set_a_price(): void
    {
        $me = Buyer::factory()->create();
        $product = $this->product(price: 1250.75);

        Sanctum::actingAs($me);

        $this->postJson(route('api.orders.store'), [
            'items' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'price_minor' => 1,
                'unit_price' => 1,
                'line_total_minor' => 1,
            ]],
            'subtotal_minor' => 1,
            'total_minor' => 1,
        ])->assertCreated();

        // The catalogue's price, not the one the body offered.
        $this->assertSame(125075, OrderItem::query()->sole()->price_minor);
        $this->assertSame(125075, Order::query()->sole()->total_minor);
    }

    public function test_a_two_shop_basket_answers_with_one_parcel_per_shop_and_one_reference(): void
    {
        $me = Buyer::factory()->create();
        $tote = $this->product('Loom & Larder', price: 1000.00);
        $bowls = $this->product('Tahanan Ceramics', price: 500.00, name: 'Stoneware Rice Bowl Set');

        Sanctum::actingAs($me);

        $response = $this->postJson(route('api.orders.store'), [
            'items' => [
                ['product_id' => $tote->id, 'quantity' => 1],
                ['product_id' => $bowls->id, 'quantity' => 1],
            ],
        ])->assertCreated();

        $response->assertJsonCount(2, 'data');

        $this->assertSame(
            ['Loom & Larder', 'Tahanan Ceramics'],
            $response->json('data.*.shop'),
        );

        // One checkout, one order number: both parcels report it, and it is the
        // same string in `meta` — that is how the app groups the rows it draws.
        $this->assertSame(
            [$response->json('meta.reference')],
            array_values(array_unique($response->json('data.*.reference'))),
        );
        $this->assertSame(150000, $response->json('meta.subtotal_minor'));

        // Two seller orders under one order row: the split is the checkout's, not
        // the request's.
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(2, SellerOrder::query()->count());
        $this->assertSame(2, Payment::query()->count());
    }

    public function test_a_voucher_code_is_applied_and_its_money_lands_in_meta(): void
    {
        $me = Buyer::factory()->create();
        $product = $this->product(price: 1250.75);
        Voucher::factory()->fixed(20000)->create(['code' => 'ZEFANYANEW']);

        Sanctum::actingAs($me);

        $response = $this->postJson(route('api.orders.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            // Lower-case on purpose: `resolveCode` upper-cases what the shopper
            // typed, and a phone keyboard is no reason to refuse a valid code.
            'voucher_code' => 'zefanyanew',
        ])->assertCreated();

        $this->assertSame(20000, $response->json('meta.discount_minor'));
        $this->assertSame(230150, $response->json('meta.total_minor'));
        $this->assertSame(20000, Order::query()->sole()->discount_minor);
    }

    public function test_a_voucher_that_does_not_apply_is_refused_rather_than_dropped(): void
    {
        $me = Buyer::factory()->create();
        $product = $this->product(price: 1250.75);

        Sanctum::actingAs($me);

        // Silently dropping it would place a full-price order while the phone's
        // basket still showed a discount. Refusing keeps the two in step.
        $response = $this->postJson(route('api.orders.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'voucher_code' => 'NOT-A-REAL-CODE',
        ])->assertStatus(422);

        $this->assertSame('NOT-A-REAL-CODE', $response->json('voucher_rejected'));
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_basket_that_is_no_longer_placeable_answers_422_and_writes_nothing(): void
    {
        $me = Buyer::factory()->create();
        $product = $this->product(stock: 1);

        Sanctum::actingAs($me);

        $response = $this->postJson(route('api.orders.store'), [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ])->assertStatus(422);

        // A sentence the app can show, rather than an opaque 500: the phone's
        // basket is stale, and refreshing it is the person's next act.
        $this->assertIsString($response->json('message'));
        $this->assertNotSame('', $response->json('message'));

        // Rolled back whole: no order, no payment, and the stock is untouched.
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, SellerOrder::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(1, $product->fresh()->stock_quantity);
    }

    public function test_validation_refuses_an_empty_or_malformed_basket(): void
    {
        Sanctum::actingAs(Buyer::factory()->create());

        $this->postJson(route('api.orders.store'), ['items' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->postJson(route('api.orders.store'), ['items' => [['product_id' => 1, 'quantity' => 0]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.quantity');

        $this->postJson(route('api.orders.store'), ['items' => [['quantity' => 1]]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.product_id');
    }
}
```

### web/tests\Feature\Api\ProductCatalogApiTest.php  (370 lines)

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Seller\Seller;
use App\Models\Shared\Category;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * `GET /api/products` and `GET /api/products/{product}` — the app's catalogue.
 *
 * What these are for, beyond "it returns a list": the app's product page is
 * driven by six fields, and every one of them is a chance to be quietly wrong —
 * an id that arrives as a number breaks the wishlist's set membership, a price in
 * pesos renders as a hundred times too much, an unordered image list puts the
 * wrong photo on the cover.
 *
 * The visibility rules get the most attention, because they are the only ones
 * with a *person* on the other side: a draft product or an unapproved seller's
 * product must not be listed, must not be openable, and must not be
 * distinguishable from a product that never existed.
 */
class ProductCatalogApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** A category row — the migration seeds fourteen, so this reads one. */
    private function category(string $label = 'Home and Garden'): Category
    {
        return Category::query()->where('label', $label)->sole();
    }

    private function approvedSeller(string $businessName = 'Loom & Larder'): Seller
    {
        return Seller::factory()->create([
            'business_name' => $businessName,
            'status' => 'approved',
        ]);
    }

    /** @return array{0: Product, 1: Seller} */
    private function product(array $overrides = []): array
    {
        $seller = $this->approvedSeller();

        $product = Product::factory()->create(array_merge([
            'seller_id' => $seller->id,
            'category_id' => $this->category()->id,
            'name' => 'Handwoven Rattan Tote',
            'price' => 1250.75,
            'status' => 'active',
        ], $overrides));

        return [$product, $seller];
    }

    private function list(): TestResponse
    {
        return $this->getJson(route('api.products.index'));
    }

    // ── What one product looks like on the wire ──────────────────────────────

    public function test_it_returns_the_envelope_the_apps_helpers_read(): void
    {
        [$product] = $this->product();

        $this->list()
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['id', 'name', 'description', 'category', 'price_minor', 'in_stock', 'seller' => ['name'], 'images'],
                ],
            ])
            ->assertJsonPath('data.0.name', $product->name);
    }

    public function test_the_id_is_a_string_so_the_apps_id_sets_still_match(): void
    {
        [$product] = $this->product();

        $id = $this->list()->json('data.0.id');

        // The app keys its wishlist, recently-viewed rail and order joins on
        // string ids that come from the mock catalogue's 'p1'/'p2'. A JSON number
        // here would make a live product un-addable to a wishlist that already
        // holds a mock one.
        $this->assertIsString($id);
        $this->assertSame((string) $product->id, $id);
    }

    public function test_the_price_crosses_as_centavos(): void
    {
        $this->product(['price' => 1250.75]);

        // 1250.75 pesos must arrive as 125075, not 1250.75. The app multiplies
        // nothing on the way in — `money.dart` reads minor units and divides once
        // — so this field being in pesos would render as ₱125,075.00.
        $this->list()->assertJsonPath('data.0.price_minor', 125075);
    }

    public function test_a_whole_peso_price_keeps_its_two_centavo_digits(): void
    {
        $this->product(['price' => 60]);

        $this->list()->assertJsonPath('data.0.price_minor', 6000);
    }

    public function test_the_category_is_its_label_not_an_id(): void
    {
        $this->product();

        $this->list()->assertJsonPath('data.0.category', 'Home and Garden');
    }

    public function test_the_sellers_shop_name_is_what_names_the_product(): void
    {
        $this->product(['name' => 'Rattan Tote']);
        $ceramics = $this->approvedSeller('Tahanan Ceramics');
        Product::factory()->create([
            'seller_id' => $ceramics->id,
            'category_id' => $this->category()->id,
            'name' => 'Stoneware Mug',
            'status' => 'active',
        ]);

        $names = collect($this->list()->json('data'))->pluck('seller.name');

        $this->assertContains('Loom & Larder', $names);
        $this->assertContains('Tahanan Ceramics', $names);
    }

    public function test_the_cover_photo_comes_first_even_when_upload_order_disagrees(): void
    {
        Storage::fake('public');
        [$product] = $this->product();

        // Upload order is not display order: the second image is flagged primary.
        ProductImage::factory()->create([
            'product_id' => $product->id, 'path' => 'products/a.jpg', 'is_primary' => false, 'sort_order' => 0,
        ]);
        ProductImage::factory()->create([
            'product_id' => $product->id, 'path' => 'products/b.jpg', 'is_primary' => true, 'sort_order' => 1,
        ]);

        $images = $this->getJson(route('api.products.show', $product))->json('data.images');

        $this->assertCount(2, $images);
        $this->assertStringContainsString('products/b.jpg', $images[0]);
        $this->assertStringContainsString('products/a.jpg', $images[1]);
    }

    public function test_a_product_with_no_flagged_cover_still_has_one(): void
    {
        Storage::fake('public');
        [$product] = $this->product();

        // The primary flag is set on upload, not by a constraint, so a row without
        // it is possible. The gallery's first frame must still be a real photo.
        ProductImage::factory()->create([
            'product_id' => $product->id, 'path' => 'products/only.jpg', 'is_primary' => false, 'sort_order' => 0,
        ]);

        $images = $this->getJson(route('api.products.show', $product))->json('data.images');

        $this->assertCount(1, $images);
        $this->assertStringContainsString('products/only.jpg', $images[0]);
    }

    public function test_a_product_with_no_photos_says_so_rather_than_inventing_one(): void
    {
        [$product] = $this->product();

        // An empty list, not a stock photo: the app falls back to a labelled
        // placeholder of its own, and a URL invented here would be nobody's photo.
        $this->assertSame([], $this->getJson(route('api.products.show', $product))->json('data.images'));
    }

    public function test_a_photo_that_is_already_a_url_is_not_run_through_the_disk(): void
    {
        [$product] = $this->product();

        // A seeded or externally hosted photo is a whole URL already. Wrapping it
        // in the disk's URL builder would produce `/storage/https://…`, which is
        // not a URL anyone can fetch.
        ProductImage::factory()->create([
            'product_id' => $product->id,
            'path' => 'https://picsum.photos/seed/demo-0/1000/1000',
            'is_primary' => true,
        ]);

        $images = $this->getJson(route('api.products.show', $product))->json('data.images');

        $this->assertSame(['https://picsum.photos/seed/demo-0/1000/1000'], $images);
    }

    public function test_an_uncategorised_product_still_reads_as_a_category(): void
    {
        [$product] = $this->product(['category_id' => null]);

        $this->assertSame('Uncategorised', $this->getJson(route('api.products.show', $product))->json('data.category'));
    }

    // ── Who may see what ─────────────────────────────────────────────────────

    public function test_a_draft_product_is_neither_listed_nor_openable(): void
    {
        [$product] = $this->product(['status' => 'draft']);

        $this->list()->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('api.products.show', $product))->assertNotFound();
    }

    public function test_an_inactive_product_is_neither_listed_nor_openable(): void
    {
        [$product] = $this->product(['status' => 'inactive']);

        $this->list()->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('api.products.show', $product))->assertNotFound();
    }

    public function test_an_unapproved_sellers_product_is_neither_listed_nor_openable(): void
    {
        [$product] = $this->product();
        $product->seller->update(['status' => 'pending_approval']);

        // Not a 403: a shop window says "not here" about an item it will not show,
        // and a 403 would confirm the id exists.
        $this->list()->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(route('api.products.show', $product))->assertNotFound();
    }

    public function test_a_sold_out_product_is_still_listed_and_says_so(): void
    {
        [$product] = $this->product(['stock_quantity' => 0]);

        // Deliberately *not* hidden: an item that vanishes from a catalogue looks
        // like a bug, and the app has no stock UI to explain an absence with. The
        // flag carries the fact instead.
        $this->list()->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.in_stock', false);

        $this->assertTrue($product->fresh()->isOutOfStock());
    }

    public function test_a_deleted_product_is_not_openable(): void
    {
        [$product] = $this->product();
        $product->delete();

        $this->getJson(route('api.products.show', $product))->assertNotFound();
    }

    public function test_the_catalogue_needs_no_token(): void
    {
        $this->product();

        // No `Authorization` header anywhere in this class, by design: browsing is
        // not a per-buyer act, and the web's own catalogue pages sit outside their
        // buyer-auth group for the same reason.
        $this->list()->assertOk()->assertJsonCount(1, 'data');
    }

    // ── The three filters the app can ask for ────────────────────────────────

    public function test_it_filters_by_category_label(): void
    {
        $this->product(['name' => 'Rattan Tote']);
        Product::factory()->create([
            'seller_id' => $this->approvedSeller()->id,
            'category_id' => $this->category('Pet Supplies')->id,
            'name' => 'Rope Leash',
            'status' => 'active',
        ]);

        $response = $this->getJson(route('api.products.index', ['category' => 'Pet Supplies']));

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Rope Leash', $response->json('data.0.name'));
    }

    public function test_an_unknown_category_is_an_empty_list_not_an_error(): void
    {
        $this->product();

        // The app renders an empty state for an empty list and an error-with-retry
        // for a throw. A filter that matches nothing is the first of those — and
        // the app's own mock catalogue has categories the server does not
        // ('Fashion'), so this is a shape the app can really produce.
        $this->getJson(route('api.products.index', ['category' => 'Fashion']))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_it_searches_by_name(): void
    {
        $this->product(['name' => 'Handwoven Rattan Tote']);
        Product::factory()->create([
            'seller_id' => $this->approvedSeller()->id,
            'category_id' => $this->category()->id,
            'name' => 'Linen Throw',
            'status' => 'active',
        ]);

        $response = $this->getJson(route('api.products.index', ['q' => 'rattan']));

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Handwoven Rattan Tote', $response->json('data.0.name'));
    }

    public function test_search_also_matches_the_category_name(): void
    {
        $this->product(['category_id' => $this->category('Pet Supplies')->id, 'name' => 'Rope Leash']);

        // The same two fields the mock catalogue matches, so a query does not
        // return different products depending on which mode the app is in.
        $this->getJson(route('api.products.index', ['q' => 'pet']))
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_search_does_not_match_the_description(): void
    {
        $this->product(['name' => 'Rope Leash', 'description' => 'Made from rattan offcuts']);

        // Name and category only: a description match makes a two-word query
        // return nearly everything, which reads as broken rather than thorough.
        $this->getJson(route('api.products.index', ['q' => 'rattan']))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_it_filters_by_shop_name(): void
    {
        $this->product(['name' => 'Rattan Tote']);
        $ceramics = $this->approvedSeller('Tahanan Ceramics');
        Product::factory()->create([
            'seller_id' => $ceramics->id,
            'category_id' => $this->category()->id,
            'name' => 'Stoneware Mug',
            'status' => 'active',
        ]);

        $response = $this->getJson(route('api.products.index', ['seller' => 'Tahanan Ceramics']));

        $response->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame('Stoneware Mug', $response->json('data.0.name'));
    }

    public function test_no_filters_returns_every_visible_product_newest_first(): void
    {
        $this->product(['name' => 'First']);
        Product::factory()->create([
            'seller_id' => $this->approvedSeller()->id,
            'category_id' => $this->category()->id,
            'name' => 'Second',
            'status' => 'active',
        ]);

        $response = $this->list()->assertOk()->assertJsonCount(2, 'data');

        // An unbounded list still needs a stable order, or two identical requests
        // can render in two different orders.
        $this->assertSame('Second', $response->json('data.0.name'));
    }
}
```

### web/tests\Feature\Api\VoucherApiTest.php  (111 lines)

```php
<?php

namespace Tests\Feature\Api;

use App\Models\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/vouchers` — the vouchers a buyer may choose from.
 *
 * Two things are being pinned here, and the second is the one worth reading.
 *
 * 1. **Only usable vouchers are listed.** Inactive, expired and used-up vouchers
 *    are absent, because a picker that offers something the cart will refuse is
 *    worse than a shorter list.
 * 2. **`value` has two scales and `type` says which.** `vouchers.value` is
 *    centavos for a fixed voucher and a whole percentage for a percent one — a
 *    real quirk of this schema, not an accident of the resource. Flattening it
 *    into one unit (saying everything is centavos) would make a 10%-off voucher
 *    into a ₱0.10 one, so the tests below check both scales explicitly.
 *
 * What is *not* here: any cap or category scope. The app's own `Voucher` model
 * carries a `maxDiscount` and a category, and its pricing engine computes with
 * them, but the server has neither — inventing a cap here would be a money number
 * the two sides disagree about, which is why the app's mapper refuses to offer
 * what it cannot represent. See `docs/api-contract.md` OPEN QUESTIONS §0.1/§0.2.
 */
class VoucherApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_lists_an_active_voucher_with_its_facts(): void
    {
        Voucher::factory()->fixed(20000)->minSpend(50000)->create(['code' => 'ZEFANYANEW']);

        $this->getJson(route('api.vouchers.index'))
            ->assertOk()
            ->assertJsonStructure(['data' => [['code', 'type', 'value', 'min_spend_minor', 'expires_at']]])
            ->assertJsonPath('data.0.code', 'ZEFANYANEW')
            ->assertJsonPath('data.0.type', 'fixed')
            // ₱200.00 in centavos, and ₱500.00 as the minimum spend.
            ->assertJsonPath('data.0.value', 20000)
            ->assertJsonPath('data.0.min_spend_minor', 50000);
    }

    public function test_a_percent_voucher_reports_a_whole_percentage_not_centavos(): void
    {
        Voucher::factory()->percent(10)->create(['code' => 'WELCOME10']);

        // Not 1000: a percentage is stored as the number 10, and the type field is
        // what tells a reader which scale it is looking at.
        $this->getJson(route('api.vouchers.index'))
            ->assertOk()
            ->assertJsonPath('data.0.type', 'percent')
            ->assertJsonPath('data.0.value', 10);
    }

    public function test_a_voucher_with_no_minimum_spend_or_expiry_says_so_with_nulls(): void
    {
        Voucher::factory()->fixed(5000)->create();

        $this->getJson(route('api.vouchers.index'))
            ->assertOk()
            ->assertJsonPath('data.0.min_spend_minor', null)
            ->assertJsonPath('data.0.expires_at', null);
    }

    public function test_an_inactive_voucher_is_not_offered(): void
    {
        Voucher::factory()->inactive()->create();

        $this->getJson(route('api.vouchers.index'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_an_expired_voucher_is_not_offered(): void
    {
        Voucher::factory()->expired()->create();

        $this->getJson(route('api.vouchers.index'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_voucher_at_its_usage_limit_is_not_offered(): void
    {
        Voucher::factory()->create(['usage_limit' => 3, 'times_used' => 3]);

        $this->getJson(route('api.vouchers.index'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_voucher_under_its_usage_limit_is_offered(): void
    {
        Voucher::factory()->create(['usage_limit' => 3, 'times_used' => 2]);

        $this->getJson(route('api.vouchers.index'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_voucher_expiring_later_today_is_still_offered(): void
    {
        Voucher::factory()->create(['expires_at' => now()->addHour()]);

        // The date boundary is the one an off-by-one hides behind: 'expires_at' on
        // a timestamp is a moment, not a day, so a voucher that ends in an hour is
        // valid now and must not be filtered out by a date-only comparison.
        $this->getJson(route('api.vouchers.index'))->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_it_needs_no_token(): void
    {
        $this->getJson(route('api.vouchers.index'))->assertOk();
    }
}
```

