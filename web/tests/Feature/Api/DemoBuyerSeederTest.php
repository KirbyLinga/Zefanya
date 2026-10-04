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
