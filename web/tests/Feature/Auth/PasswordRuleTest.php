<?php

namespace Tests\Feature\Auth;

use App\Models\Buyer\Buyer;
use App\Support\PasswordRule;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Shared password rule: TC-*-015..019 (Buyer 017..020), TC-RID-021..022, BUY-ACCT-005.
 */
class PasswordRuleTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{string, ?string}> */
    public static function passwords(): array
    {
        return [
            '8 characters, all types' => ['Bili@202', null],
            'workbook valid' => ['Mamimili@2026', null],
            'underscore is special' => ['Hatid_2026', null],
            '7 characters' => ['Bili@26', 'Password must be at least 8 characters.'],
            'no uppercase' => ['mamimili@2026', 'Password must include an uppercase letter.'],
            'no lowercase' => ['MAMIMILI@2026', 'Password must include a lowercase letter.'],
            'no number' => ['Mamimili@abcd', 'Password must include a number.'],
            'no special character' => ['Mamimili2026', 'Password must include a special character.'],
            'short and missing two' => ['abc1', 'Password must be at least 8 characters and include an uppercase letter and a special character.'],
            'missing three' => ['abcdefgh', 'Password must include an uppercase letter, a number and a special character.'],
            'over 72 bytes' => [str_repeat('Aa1!', 19), 'Password must not exceed 72 bytes.'],
        ];
    }

    #[DataProvider('passwords')]
    public function test_each_failing_rule_is_named(string $password, ?string $message): void
    {
        $this->assertSame($message, PasswordRule::error($password));
    }

    public function test_logistics_registration_uses_the_shared_rule(): void
    {
        // Several submissions in one test; the per-IP registration limit is not under test here.
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach (['Hati@26', 'hatid@2026', 'HATID@2026', 'Hatid@abcd', 'Hatid2026'] as $password) {
            $this->postJson(route('register.logistics.store'), ['password' => $password, 'password_confirmation' => $password])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['password' => PasswordRule::error($password)]);
        }

        $this->postJson(route('register.logistics.store'), ['password' => 'Hatid@2026', 'password_confirmation' => 'Hatid@2027'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['password' => 'Passwords do not match.']);

        $this->postJson(route('register.logistics.store'), ['password' => 'Hatid@26', 'password_confirmation' => 'Hatid@26'])
            ->assertStatus(422)
            ->assertJsonMissingValidationErrors('password');
    }

    public function test_buyer_change_password_rejects_a_weak_password(): void
    {
        $buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
            'password' => Hash::make('Mamimili@2026'),
        ]);

        $this->actingAs($buyer, 'buyer')
            ->patch(route('buyer.account.password.update'), [
                'current_password' => 'Mamimili@2026',
                'new_password' => 'Mamimili2027',
                'new_password_confirmation' => 'Mamimili2027',
            ])
            ->assertSessionHasErrors(['new_password' => 'Password must include a special character.']);

        $this->assertTrue(Hash::check('Mamimili@2026', $buyer->fresh()->password));
    }
}
