<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\Admin;
use App\Services\Admin\TwoFactorAuthenticator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Admin-only TOTP 2FA: password alone must not open a session for an enrolled
 * admin, recovery codes are single-use, enrolment requires a valid code, and
 * disabling requires the password.
 */
class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function service(): TwoFactorAuthenticator
    {
        return app(TwoFactorAuthenticator::class);
    }

    /**
     * @return array{0: Admin, 1: string, 2: list<string>}
     */
    private function enrolledAdmin(): array
    {
        $secret = $this->service()->generateSecret();
        $recovery = $this->service()->generateRecoveryCodes();

        $admin = Admin::factory()->create();

        $admin->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $recovery['hashed'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return [$admin->fresh(), $secret, $recovery['plain']];
    }

    public function test_password_login_alone_does_not_authenticate_an_enrolled_admin(): void
    {
        [$admin] = $this->enrolledAdmin();

        $response = $this->post(route('admin.login.post'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('admin.two-factor.challenge'));
        $response->assertSessionHas('admin.2fa.id', $admin->id);
        $this->assertGuest('admin');
    }

    public function test_challenge_page_renders_after_the_password_step(): void
    {
        [$admin] = $this->enrolledAdmin();

        $this->post(route('admin.login.post'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $this->get(route('admin.two-factor.challenge'))
            ->assertOk()
            ->assertSee('Two-factor verification');
    }

    public function test_challenge_page_without_a_pending_login_goes_back_to_login(): void
    {
        $this->get(route('admin.two-factor.challenge'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_valid_totp_code_completes_the_login(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();

        $this->post(route('admin.login.post'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.two-factor.challenge'));

        $response = $this->post(route('admin.two-factor.verify'), [
            'code' => $this->service()->currentCode($secret),
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_invalid_code_is_rejected_and_no_session_opens(): void
    {
        [$admin] = $this->enrolledAdmin();

        $this->post(route('admin.login.post'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $response = $this->from(route('admin.two-factor.challenge'))
            ->post(route('admin.two-factor.verify'), ['code' => '000000']);

        $response->assertRedirect(route('admin.two-factor.challenge'));
        $response->assertSessionHasErrors('code');
        $this->assertGuest('admin');
    }

    public function test_recovery_code_is_single_use(): void
    {
        [$admin, , $codes] = $this->enrolledAdmin();
        $recoveryCode = $codes[0];

        $this->post(route('admin.login.post'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $this->post(route('admin.two-factor.verify'), ['code' => $recoveryCode])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');

        // A used recovery code cannot be replayed.
        $this->post(route('admin.logout'));

        $this->post(route('admin.login.post'), [
            'email' => $admin->email,
            'password' => 'password',
        ]);

        $this->from(route('admin.two-factor.challenge'))
            ->post(route('admin.two-factor.verify'), ['code' => $recoveryCode])
            ->assertSessionHasErrors('code');

        $this->assertGuest('admin');
    }

    public function test_enrolment_requires_a_valid_code_and_encrypts_the_secret(): void
    {
        $admin = Admin::factory()->create();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.two-factor.show'))
            ->assertOk()
            ->assertSessionHas('admin.2fa.pending_secret');

        $secret = (string) session('admin.2fa.pending_secret');

        $this->post(route('admin.two-factor.enable'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($admin->fresh()->hasEnabledTwoFactor());

        $this->post(route('admin.two-factor.enable'), [
            'code' => $this->service()->currentCode($secret),
        ])->assertRedirect(route('admin.two-factor.show'));

        $fresh = $admin->fresh();

        $this->assertTrue($fresh->hasEnabledTwoFactor());
        $this->assertNotEmpty($fresh->twoFactorRecoveryCodeHashes());

        $raw = DB::table('admins')->where('id', $admin->id)->value('two_factor_secret');
        $this->assertNotSame($secret, $raw, 'The TOTP secret must be encrypted at rest.');
    }

    public function test_disable_requires_the_correct_password(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.two-factor.disable'), [
                'password' => 'wrong-password',
                'code' => $this->service()->currentCode($secret),
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($admin->fresh()->hasEnabledTwoFactor());

        $this->post(route('admin.two-factor.disable'), [
            'password' => 'password',
            'code' => $this->service()->currentCode($secret),
        ]);

        $this->assertFalse($admin->fresh()->hasEnabledTwoFactor());
    }

    public function test_enrolment_can_be_enforced_by_configuration(): void
    {
        $admin = Admin::factory()->create();

        config()->set('admin.require_two_factor', true);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.two-factor.show'));

        // The setup screen stays reachable while unenrolled.
        $this->actingAs($admin, 'admin')
            ->get(route('admin.two-factor.show'))
            ->assertOk();

        config()->set('admin.require_two_factor', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_secret_and_recovery_hashes_are_hidden_from_serialization(): void
    {
        [$admin] = $this->enrolledAdmin();

        $array = $admin->toArray();

        $this->assertArrayNotHasKey('two_factor_secret', $array);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $array);
    }

    public function test_two_factor_columns_do_not_exist_on_other_role_tables(): void
    {
        // Guarantees the migration stayed admin-scoped.
        foreach (['buyers', 'sellers', 'logistics_providers', 'riders'] as $table) {
            $this->assertFalse(
                Schema::hasColumn($table, 'two_factor_secret'),
                "{$table} must not have admin 2FA columns.",
            );
        }
    }
}
