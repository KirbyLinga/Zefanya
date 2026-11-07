<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function enrolledAdmin(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->forceFill([
            'two_factor_secret' => 'JBSWY3DPEHPK3PXP',
            'two_factor_recovery_codes' => ['retained-recovery-hash'],
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $admin;
    }

    public function test_password_login_authenticates_an_enrolled_admin_and_clears_stale_challenge_state(): void
    {
        $admin = $this->enrolledAdmin();
        $stored = $admin->getRawOriginal();
        $this->withSession(['admin.2fa' => [
            'id' => 999,
            'remember' => true,
            'pending_secret' => 'stale-secret',
            'recovery_codes' => ['stale-code'],
        ]]);
        $sessionId = session()->getId();

        $this->post(route('admin.login.post'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'))
            ->assertSessionMissing('admin.2fa');

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertNotSame($sessionId, session()->getId());
        $this->get(route('admin.dashboard'))->assertOk();
        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $column) {
            $this->assertSame($stored[$column], $admin->fresh()->getRawOriginal($column));
        }
    }

    public function test_old_enforcement_configuration_does_not_gate_password_login_or_admin_pages(): void
    {
        config(['admin.require_two_factor' => true]);
        $admin = Admin::factory()->create();

        $this->post(route('admin.login.post'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.settings.index'))->assertOk();
    }

    public function test_retired_two_factor_endpoints_are_not_available_to_guests_or_admins(): void
    {
        $endpoints = [
            ['GET', '/admin/two-factor/challenge'],
            ['POST', '/admin/two-factor/challenge'],
            ['GET', '/admin/security/two-factor'],
            ['POST', '/admin/security/two-factor/enable'],
            ['POST', '/admin/security/two-factor/disable'],
        ];
        foreach ($endpoints as [$method, $url]) {
            $this->call($method, $url)->assertNotFound();
        }

        $this->actingAs($this->enrolledAdmin(), 'admin');
        foreach ($endpoints as [$method, $url]) {
            $this->call($method, $url)->assertNotFound();
        }
        foreach (['challenge', 'verify', 'show', 'enable', 'disable'] as $name) {
            $this->assertFalse(Route::has('admin.two-factor.'.$name));
        }
    }

    public function test_password_authentication_and_role_boundaries_remain_required(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $admin = $this->enrolledAdmin();
        $this->post(route('admin.login.post'), [
            'email' => $admin->email,
            'password' => 'incorrect-password',
        ])->assertSessionHasErrors('email');
        $this->assertGuest('admin');

        $support = Admin::factory()->create(['role' => 'support']);
        $this->post(route('admin.login.post'), [
            'email' => $support->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.settings.index'))->assertForbidden();
        $this->get(route('admin.registrations.index'))->assertForbidden();
    }

    public function test_enrolled_admin_can_remember_login_and_logout_invalidates_stale_state(): void
    {
        $admin = $this->enrolledAdmin();
        $this->post(route('admin.login.post'), [
            'email' => $admin->email,
            'password' => 'password',
            'remember' => '1',
        ])->assertRedirect(route('admin.dashboard'))
            ->assertCookie(Auth::guard('admin')->getRecallerName());
        $this->assertNotEmpty($admin->fresh()->getRememberToken());
        $this->withSession(['admin.2fa.id' => $admin->id]);

        $this->post(route('admin.logout'))->assertRedirect(route('admin.login'))
            ->assertSessionMissing('admin.2fa');
        $this->assertGuest('admin');
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
    }

    public function test_login_and_logout_keep_csrf_protection(): void
    {
        $this->app->instance('env', 'production');
        $admin = $this->enrolledAdmin();
        $this->post(route('admin.login.post'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertStatus(419);
        $this->assertGuest('admin');

        $this->actingAs($admin, 'admin')->post(route('admin.logout'))->assertStatus(419);
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_login_and_logout_keep_their_rate_limit_middleware(): void
    {
        $routes = Route::getRoutes();
        $this->assertContains('throttle:login', $routes->getByName('admin.login.post')->gatherMiddleware());
        $this->assertContains('throttle:logout', $routes->getByName('admin.logout')->gatherMiddleware());
        $this->assertContains('auth:admin', $routes->getByName('admin.logout')->gatherMiddleware());
    }

    public function test_retained_secret_and_recovery_hashes_stay_hidden_from_serialization(): void
    {
        $array = $this->enrolledAdmin()->toArray();
        $this->assertArrayNotHasKey('two_factor_secret', $array);
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $array);
    }
}
