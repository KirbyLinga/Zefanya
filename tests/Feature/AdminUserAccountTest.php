<?php

namespace Tests\Feature;

use App\Models\Admin\Admin;
use App\Models\Seller\Seller;
use App\Notifications\Admin\AccountStatusChanged;
use App\Notifications\Buyer\BuyerRegistrationDecision;
use App\Notifications\Seller\SellerRegistrationDecision;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AdminUserAccountTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'User Admin',
            'email' => 'users.'.uniqid().'@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    public function test_admin_can_list_and_suspend_an_approved_seller(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $seller = Seller::factory()->create(['status' => 'approved']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.users.index', ['type' => 'seller']))
            ->assertOk()
            ->assertSee($seller->email);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.seller.suspend', $seller))
            ->assertRedirect();

        $this->assertSame('suspended', $seller->fresh()->status);

        Notification::assertSentTo($seller, AccountStatusChanged::class, function (AccountStatusChanged $n, array $channels): bool {
            return $n->status === 'suspended' && $channels === ['mail'];
        });
    }

    public function test_admin_can_reactivate_a_suspended_seller(): void
    {
        Notification::fake();

        $admin = $this->admin();
        $seller = Seller::factory()->create(['status' => 'suspended']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.users.seller.reactivate', $seller))
            ->assertRedirect();

        $this->assertSame('approved', $seller->fresh()->status);

        Notification::assertSentTo($seller, AccountStatusChanged::class, function (AccountStatusChanged $n, array $channels): bool {
            return $n->status === 'approved' && $channels === ['mail'];
        });
    }

    public function test_registration_decision_notifications_use_mail(): void
    {
        $this->assertSame(['mail'], (new BuyerRegistrationDecision('approved'))->via((object) []));
        $this->assertSame(['mail'], (new SellerRegistrationDecision('rejected', 'bad ID'))->via((object) []));
    }

    public function test_suspended_seller_cannot_open_the_seller_panel(): void
    {
        $seller = Seller::factory()->create([
            'status' => 'suspended',
            'email' => 'suspended.'.uniqid().'@example.com',
            'password' => 'password',
        ]);

        $this->actingAs($seller, 'seller')
            ->get(route('seller.dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest('seller');
    }
}
