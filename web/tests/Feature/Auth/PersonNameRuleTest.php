<?php

namespace Tests\Feature\Auth;

use App\Http\Requests\Buyer\StoreBuyerRegistrationRequest;
use App\Http\Requests\Logistics\StoreLogisticsRegistrationRequest;
use App\Http\Requests\Logistics\StoreRiderRequest;
use App\Http\Requests\Rider\StoreRiderRegistrationRequest;
use App\Http\Requests\Seller\StoreSellerRegistrationRequest;
use App\Models\Buyer\Buyer;
use App\Support\PersonName;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Shared name rule for every role: TC-BUY-004..006, TC-SEL-004..006,
 * TC-LOG-004..006, TC-RID-004..005 (and BUY-ACCT-002 for the profile edit).
 */
class PersonNameRuleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const NAME_50 = 'Maria Cristina Angelica Bernadette Josefina Leonor';

    private const NAME_51 = 'Maria Cristina Angelica Bernadette Josefina Leonora';

    /** @return array<string, array{string}> */
    public static function acceptedNames(): array
    {
        return [
            'two letters' => ['Jo'],
            'with a space' => ['Juan Miguel'],
            'with ñ' => ['Niño Angelo'],
            'hyphen and apostrophe' => ["Peñaflor-O'Hara"],
            'hyphen and apostrophe (seller)' => ["Peñaranda-D'Souza"],
            'period' => ['Ma. Clara'],
            'trailing period' => ['Jr.'],
            'fifty characters' => [self::NAME_50],
        ];
    }

    /** @return array<string, array{string}> */
    public static function rejectedNames(): array
    {
        return [
            'empty' => [''],
            'one character' => ['J'],
            'fifty-one characters' => [self::NAME_51],
            'digits' => ['Juan Miguel123'],
            'symbols' => ['Dela Cruz@#'],
            'leading hyphen' => ['-Juan'],
            'trailing hyphen' => ['Juan-'],
            'tab' => ["Juan\tMiguel"],
            'markup' => ['<b>Juan</b>'],
        ];
    }

    #[DataProvider('acceptedNames')]
    public function test_valid_names_are_accepted(string $name): void
    {
        $validator = Validator::make(['first_name' => $name], ['first_name' => PersonName::rules()]);

        $this->assertFalse($validator->fails(), $name.' should be accepted: '.$validator->errors()->first());
    }

    #[DataProvider('rejectedNames')]
    public function test_invalid_names_are_rejected(string $name): void
    {
        $validator = Validator::make(['first_name' => $name], ['first_name' => PersonName::rules()]);

        $this->assertTrue($validator->fails(), $name.' should be rejected.');
    }

    public function test_every_role_uses_the_shared_name_rule(): void
    {
        foreach ([
            StoreBuyerRegistrationRequest::class,
            StoreSellerRegistrationRequest::class,
            StoreLogisticsRegistrationRequest::class,
            StoreRiderRequest::class,
            StoreRiderRegistrationRequest::class,
        ] as $request) {
            $rules = (new $request)->rules();

            $this->assertSame(PersonName::rules(), $rules['first_name'], $request.' first_name');
            $this->assertSame(PersonName::rules(), $rules['last_name'], $request.' last_name');
        }
    }

    public function test_rejection_message_names_the_allowed_characters(): void
    {
        $validator = Validator::make(
            ['first_name' => 'Carlos Antonio7'],
            ['first_name' => PersonName::rules()],
            PersonName::messages('first_name', 'First name'),
        );

        $this->assertSame(
            'First name can only contain letters, spaces, hyphens, apostrophes and periods.',
            $validator->errors()->first('first_name'),
        );
    }

    public function test_buyer_profile_saves_a_valid_name_exactly_as_typed(): void
    {
        $buyer = $this->approvedBuyer();

        $this->actingAs($buyer, 'buyer')
            ->patch(route('buyer.account.update'), $this->profilePayload('Niño Angelo', "Peñaflor-O'Hara"))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('buyers', [
            'id' => $buyer->id,
            'first_name' => 'Niño Angelo',
            'last_name' => "Peñaflor-O'Hara",
        ]);
    }

    public function test_buyer_profile_rejects_invalid_names_and_keeps_the_saved_one(): void
    {
        $buyer = $this->approvedBuyer();
        $original = $buyer->first_name;

        foreach (['Juan123', 'J', self::NAME_51] as $name) {
            $this->actingAs($buyer, 'buyer')
                ->patch(route('buyer.account.update'), $this->profilePayload($name, 'Dela Cruz'))
                ->assertSessionHasErrors('first_name');
        }

        $this->assertSame($original, $buyer->fresh()->first_name);
    }

    private function approvedBuyer(): Buyer
    {
        return Buyer::factory()->create([
            'first_name' => 'Juan Miguel',
            'status' => 'approved',
            'email_verified_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function profilePayload(string $first, string $last): array
    {
        return [
            'first_name' => $first,
            'last_name' => $last,
            'sex' => 'male',
            'contact_no' => '09171234567',
            'birthday' => '1998-05-14',
        ];
    }
}
