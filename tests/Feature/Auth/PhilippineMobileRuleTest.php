<?php

namespace Tests\Feature\Auth;

use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider\Rider;
use App\Models\Seller\Seller;
use App\Support\PhilippineMobile;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Shared PH mobile rule: TC-*-011..016 and TC-RID-009..011.
 */
class PhilippineMobileRuleTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function normalisedNumbers(): array
    {
        return [
            '09 format' => ['09171234567', '09171234567'],
            '+63 format' => ['+639171234567', '09171234567'],
            '+63 with spaces' => ['+63 917 123 4567', '09171234567'],
            'surrounding spaces' => [' 09171234567 ', '09171234567'],
        ];
    }

    #[DataProvider('normalisedNumbers')]
    public function test_valid_numbers_normalise_to_the_09_form(string $input, string $expected): void
    {
        $normalised = PhilippineMobile::normalize($input);

        $this->assertSame($expected, $normalised);
        $this->assertNull(PhilippineMobile::error($normalised));
    }

    /** @return array<string, array{string, string}> */
    public static function invalidNumbers(): array
    {
        return [
            'letters' => ['0917ABC4567', PhilippineMobile::FORMAT_MESSAGE],
            'hyphens' => ['0917-123-4567', PhilippineMobile::FORMAT_MESSAGE],
            'symbols, too short' => ['0917-123-456', PhilippineMobile::FORMAT_MESSAGE],
            'not starting with 09' => ['12345678901', PhilippineMobile::FORMAT_MESSAGE],
            'landline' => ['02-8123-4567', PhilippineMobile::FORMAT_MESSAGE],
            '10 digits' => ['0917123456', PhilippineMobile::LENGTH_MESSAGE],
            '12 digits' => ['091712345678', PhilippineMobile::LENGTH_MESSAGE],
            '+63 too short' => ['+63917123456', PhilippineMobile::LENGTH_MESSAGE_INTL],
            '+63 landline' => ['+632812345678', PhilippineMobile::FORMAT_MESSAGE],
            'other country code' => ['+19171234567', PhilippineMobile::FORMAT_MESSAGE],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_invalid_numbers_get_the_browser_message(string $input, string $message): void
    {
        $this->assertSame($message, PhilippineMobile::error(PhilippineMobile::normalize($input)));
    }

    /** @return array<string, array{class-string}> */
    public static function owners(): array
    {
        return [
            'buyer' => [Buyer::class],
            'seller' => [Seller::class],
            'logistics' => [LogisticsProvider::class],
            'rider' => [Rider::class],
        ];
    }

    #[DataProvider('owners')]
    public function test_a_number_owned_by_any_role_is_taken_for_every_role(string $model): void
    {
        $model::factory()->create(['contact_no' => '09171234567']);

        foreach (['buyer', 'seller', 'logistics', 'rider'] as $role) {
            $this->assertTrue(PhilippineMobile::isTaken('09171234567', $role, 'someone.else@example.com'), $role);
        }

        $this->assertFalse(PhilippineMobile::isTaken('09179999999', 'buyer', 'someone.else@example.com'));
    }

    public function test_own_unverified_registration_with_the_same_email_does_not_block_a_retry(): void
    {
        Buyer::factory()->create([
            'email' => 'retry@example.com',
            'contact_no' => '09171234567',
            'status' => 'unverified',
        ]);

        $this->assertFalse(PhilippineMobile::isTaken('09171234567', 'buyer', 'RETRY@example.com'));
        $this->assertTrue(PhilippineMobile::isTaken('09171234567', 'seller', 'retry@example.com'));
    }
}
