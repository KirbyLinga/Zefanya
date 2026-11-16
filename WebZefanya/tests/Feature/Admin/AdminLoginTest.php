<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_provides_an_accessible_password_toggle(): void
    {
        $this->withoutVite()->get(route('admin.login'))->assertOk()
            ->assertSee('aria-controls="password"', false)
            ->assertSee('aria-label="Show password"', false)
            ->assertSee('aria-pressed="false"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertSee('Keep me signed in');
    }

    public function test_json_credentials_errors_are_generic_for_unknown_and_existing_accounts(): void
    {
        $admin = Admin::factory()->create();

        foreach (['missing-admin@example.com', $admin->email] as $email) {
            $this->postJson(route('admin.login.post'), ['email' => $email, 'password' => 'incorrect-password'])
                ->assertUnprocessable()
                ->assertExactJson([
                    'message' => 'Email or password is incorrect.',
                    'errors' => ['email' => ['Email or password is incorrect.']],
                ]);
            $this->assertGuest('admin');
        }
    }

    public function test_standard_form_errors_retain_email_and_remember_without_password(): void
    {
        $this->from(route('admin.login'))->post(route('admin.login.post'), [
            'email' => 'form-admin@example.com',
            'password' => 'incorrect-password',
            'remember' => '1',
        ])->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('email')
            ->assertSessionHas('_old_input.email', 'form-admin@example.com')
            ->assertSessionHas('_old_input.remember', '1')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_json_validation_still_returns_field_errors(): void
    {
        $this->postJson(route('admin.login.post'), ['email' => 'invalid', 'password' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_json_login_keeps_the_shared_rate_limit_and_retry_header(): void
    {
        $payload = ['email' => 'json-admin-limit@example.com', 'password' => 'incorrect-password'];
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson(route('admin.login.post'), $payload)->assertUnprocessable();
        }
        $this->postJson(route('admin.login.post'), $payload)
            ->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertGuest('admin');
    }

    public function test_json_login_preserves_the_successful_session_redirect(): void
    {
        $admin = Admin::factory()->create();
        $this->postJson(route('admin.login.post'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_json_login_authenticates_previously_enrolled_admins_with_password(): void
    {
        $admin = Admin::factory()->create();
        $admin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();

        $this->postJson(route('admin.login.post'), ['email' => $admin->email, 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionMissing('admin.2fa');
        $this->assertAuthenticatedAs($admin, 'admin');
    }
}
