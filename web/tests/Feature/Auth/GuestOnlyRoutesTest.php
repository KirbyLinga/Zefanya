<?php

namespace Tests\Feature\Auth;

use App\Enums\AuthRole;
use App\Enums\LogisticsProviderStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GuestOnlyRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const GUEST_ONLY_URLS = ['/register', '/admin/login'];

    private function signIn(string $role): void
    {
        $user = match ($role) {
            'buyer' => Buyer::factory()->create(['status' => 'approved']),
            'seller' => Seller::factory()->create(['status' => 'approved']),
            'logistics' => LogisticsProvider::factory()->create(['status' => LogisticsProviderStatus::Approved]),
            'admin' => Admin::forceCreate([
                'name' => 'Test Admin',
                'email' => 'admin@example.test',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]),
        };

        $this->actingAs($user, $role);
    }

    private function dashboard(string $role): string
    {
        return AuthRole::from($role)->dashboardUrl();
    }

    public static function roles(): array
    {
        return [['buyer'], ['seller'], ['logistics'], ['admin']];
    }

    #[DataProvider('roles')]
    public function test_signed_in_role_is_redirected_from_every_guest_only_page(string $role): void
    {
        $this->signIn($role);

        foreach (self::GUEST_ONLY_URLS as $url) {
            $this->get($url)->assertRedirect($this->dashboard($role));
        }

        $this->assertAuthenticated($role);
    }

    public function test_buyer_cannot_open_another_roles_login_or_registration(): void
    {
        $this->signIn('buyer');

        $this->get('/admin/login')->assertRedirect($this->dashboard('buyer'));
        $this->get('/register')->assertRedirect($this->dashboard('buyer'));
    }

    public function test_post_login_while_signed_in_does_not_switch_accounts(): void
    {
        $this->signIn('buyer');
        $seller = Seller::factory()->create(['status' => 'approved']);

        $this->post('/login', ['email' => $seller->email, 'password' => 'password'])
            ->assertRedirect($this->dashboard('buyer'));

        $this->assertAuthenticated('buyer');
        $this->assertGuest('seller');
    }

    public function test_json_requests_get_409_with_redirect_target(): void
    {
        $this->signIn('seller');

        $this->postJson('/register/buyer', [])
            ->assertStatus(409)
            ->assertJson(['redirect' => $this->dashboard('seller')]);
    }

    public function test_guest_can_open_registration_page(): void
    {
        $this->get('/register')->assertOk();
    }

    public function test_guest_can_open_shared_login_modal(): void
    {
        $this->get(route('home', ['login' => 1]))->assertOk()
            ->assertSee('id="loginModalOverlay"', false);
    }

    #[DataProvider('roles')]
    public function test_after_logout_guest_pages_open_again(string $role): void
    {
        $this->signIn($role);

        $this->post('/logout');

        $this->assertGuest($role);
        $this->get('/register')->assertOk();
    }
}
