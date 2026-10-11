<?php

namespace Tests\Feature\Buyer;

use App\Models\Buyer\Buyer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * Buyer "My Account" pages render the shared tab strip (Profile / Address
 * Book / Password & Security / Order History; Preferences has no route so it
 * is plain non-interactive text) and every color class resolves to a theme
 * custom property — the pages must not reintroduce raw hex palette values.
 *
 * Data behavior (update/password/address flows) is covered by the
 * registration and lifecycle tests; this class guards the shared UI shell.
 */
class BuyerAccountPagesTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Buyer $buyer;

    private static array $themeVars = [
        'bg-page',
        'bg-surface',
        'bg-thumb',
        'bg-error-soft',
        'border',
        'border-faint',
        'border-error-soft',
        'brand',
        'link',
        'btn-primary-bg',
        'btn-primary-bg-hover',
        'btn-primary-text',
        'hover-rose',
        'pill-success-bg',
        'pill-success-border',
        'status-success',
        'status-danger',
        'text-ink',
        'text-body',
        'text-label',
        'text-muted',
        'text-muted-2',
        'text-placeholder',
        'text-disabled',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = Buyer::factory()->create([
            'status' => 'approved',
            'email_verified_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function signedIn(): static
    {
        $this->actingAs($this->buyer, 'buyer');

        return $this;
    }

    /**
     * Guards the four account views (profile/address/password pages plus the
     * shared tabs include) so later color edits stay inside the
     * theme custom properties; the account shell owns no color values of its
     * own. This deliberately reads the sources rather than rendered pages,
     * because the shared buyer layout also embeds legacy raw-hex components
     * (registration modal, admin sidebar, login modal) that are out of scope.
     */
    private function assertOnlyThemeColors(): void
    {
        $sources = [
            base_path('resources/views/Components/buyer/account-tabs.blade.php'),
            base_path('resources/views/Buyer/Account/profile.blade.php'),
            base_path('resources/views/Buyer/Account/address.blade.php'),
            base_path('resources/views/Buyer/Account/password.blade.php'),
        ];

        foreach ($sources as $source) {
            $contentSection = file_get_contents($source);
            $this->assertNotFalse($contentSection, "Could not read {$source}.");

            $this->assertDoesNotMatchRegularExpression(
                '/#[0-9a-fA-F]{3,8}\b/',
                $contentSection,
                "Account views must not contain raw hex colors ({$source})."
            );

            preg_match_all('/var\(--([a-z0-9-]+)\)/', $contentSection, $matches);

            $this->assertNotEmpty($matches[1], "Expected at least one var(--…) color token ({$source}).");

            foreach (array_unique($matches[1]) as $token) {
                $this->assertContains($token, self::$themeVars, "var(--{$token}) is not a known theme token ({$source}).");
            }
        }
    }

    public function test_profile_page_renders_shared_header_tabs_and_card(): void
    {
        $html = $this->signedIn()->get(route('buyer.account.index'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Manage your personal information, contact credentials, and communication preferences.', $html);
        $this->assertStringContainsString('aria-current="page">Profile</span>', $html);
        $this->assertStringContainsString(route('buyer.account.address'), $html);
        $this->assertStringContainsString(route('buyer.account.password'), $html);
        $this->assertStringContainsString(route('buyer.orders.index'), $html);
        $this->assertStringNotContainsString('Preferences', $html, 'Preferences has no route and was removed from the tab strip.');
        $this->assertStringContainsString('Personal Profile', $html);
        $this->assertStringContainsString('Verified Account', $html);
        $this->assertStringContainsString('Save changes', $html);
        $this->assertStringContainsString('Discard', $html);
        $this->assertStringContainsString('Last updated:', $html);
        $this->assertStringContainsString($this->buyer->email, $html);
    }

    public function test_account_views_use_only_theme_color_tokens(): void
    {
        $this->assertOnlyThemeColors();
    }

    public function test_address_page_uses_the_shared_tabs_with_active_address_book(): void
    {
        $html = $this->signedIn()->get(route('buyer.account.address'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('aria-current="page">Address Book</span>', $html);
        $this->assertStringContainsString(route('buyer.account.index'), $html);
        $this->assertStringNotContainsString('Personal Profile', $html, 'The address page must not reuse the profile card.');
        $this->assertStringContainsString('Order History', $html);
        $this->assertStringContainsString('Save address', $html);
    }

    public function test_password_page_uses_the_shared_tabs_with_active_password_security(): void
    {
        $html = $this->signedIn()->get(route('buyer.account.password'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('aria-current="page">Password &amp; Security</span>', $html);
        $this->assertStringContainsString(route('buyer.account.index'), $html);
        $this->assertStringContainsString('Update password', $html);
    }

    public function test_preferences_tab_is_not_a_link(): void
    {
        $html = $this->signedIn()->get(route('buyer.account.index'))
            ->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/<a[^>]*>\s*Preferences\s*<\/a>/',
            $html,
            'Preferences has no page and must never render as a dead link.'
        );
    }

    public function test_profile_update_persists_fields_and_derives_age(): void
    {
        $response = $this->signedIn()->patch(route('buyer.account.update'), [
            'first_name' => 'Marisol',
            'last_name' => 'Villanueva',
            'middle_initial' => 'D',
            'sex' => 'female',
            'contact_no' => '09171234567',
            'birthday' => '1994-05-20',
        ]);

        $response->assertRedirect(route('buyer.account.index'))
            ->assertSessionHas('success', 'Profile updated.');

        $this->assertDatabaseHas('buyers', [
            'id' => $this->buyer->id,
            'first_name' => 'Marisol',
            'last_name' => 'Villanueva',
            'contact_no' => '09171234567',
            'age' => Carbon::parse('1994-05-20')->age,
        ]);
    }

    public function test_profile_update_rejects_bad_input(): void
    {
        $this->signedIn()->patch(route('buyer.account.update'), [
            'first_name' => '',
            'last_name' => 'Villanueva',
            'sex' => 'female',
            'contact_no' => '12345',
            'birthday' => '2999-01-01',
        ])->assertSessionHasErrors(['first_name', 'contact_no', 'birthday']);

        $this->assertDatabaseHas('buyers', [
            'id' => $this->buyer->id,
            'first_name' => $this->buyer->first_name,
        ]);
    }

    public function test_address_update_persists_codes_and_names_in_api_mode(): void
    {
        $response = $this->signedIn()->patch(route('buyer.account.address.update'), [
            'address_mode' => 'api',
            'province' => '0300000000',
            'province_name' => 'Batangas',
            'municipality' => '0306800000',
            'municipality_name' => 'Lipa City',
            'barangay' => '030680001',
            'barangay_name' => 'Barangay 1',
            'street' => '123 Rizal St., Purok 2',
            'house_number' => '123',
            'address_detail' => 'Near the plaza',
        ]);

        $response->assertRedirect(route('buyer.account.address'))
            ->assertSessionHas('success', 'Address updated.');

        $this->assertDatabaseHas('buyers', [
            'id' => $this->buyer->id,
            'address_mode' => 'api',
            'province_code' => '0300000000',
            'province_name' => 'Batangas',
            'municipality_code' => '0306800000',
            'municipality_name' => 'Lipa City',
            'barangay_code' => '030680001',
            'barangay_name' => 'Barangay 1',
            'street' => '123 Rizal St., Purok 2',
        ]);
    }

    public function test_address_update_accepts_manual_mode_without_codes(): void
    {
        $this->signedIn()->patch(route('buyer.account.address.update'), [
            'address_mode' => 'manual',
            'province_name' => 'Batangas',
            'municipality_name' => 'Lipa City',
            'barangay_name' => 'Barangay 1',
            'street' => '456 Mabini Ave.',
        ])->assertRedirect(route('buyer.account.address'))
            ->assertSessionHas('success', 'Address updated.');

        $this->assertDatabaseHas('buyers', [
            'id' => $this->buyer->id,
            'address_mode' => 'manual',
            'province_code' => null,
            'province_name' => 'Batangas',
            'street' => '456 Mabini Ave.',
        ]);
    }

    public function test_address_update_requires_street_and_codes_in_api_mode(): void
    {
        $this->signedIn()->patch(route('buyer.account.address.update'), [
            'address_mode' => 'api',
            'street' => '',
        ])->assertSessionHasErrors(['province', 'municipality', 'barangay', 'street']);
    }
}
