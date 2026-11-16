<?php

namespace Tests\Unit;

use App\Enums\AuthRole;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthRoleTest extends TestCase
{
    public function test_detection_order_is_admin_seller_logistics_buyer(): void
    {
        $this->assertSame(
            [AuthRole::Admin, AuthRole::Seller, AuthRole::Logistics, AuthRole::Buyer],
            AuthRole::detectionOrder(),
        );
    }

    public function test_public_order_excludes_admin(): void
    {
        $this->assertSame(
            [AuthRole::Seller, AuthRole::Logistics, AuthRole::Buyer],
            AuthRole::publicOrder(),
        );

        $this->assertNotContains(AuthRole::Admin, AuthRole::publicOrder());
        $this->assertSame(['seller', 'logistics', 'buyer'], AuthRole::publicGuards());
    }

    public function test_every_dashboard_route_is_registered(): void
    {
        foreach (AuthRole::cases() as $role) {
            $this->assertTrue(
                Route::has($role->dashboardRoute()),
                "Missing route [{$role->dashboardRoute()}] for AuthRole::{$role->name}",
            );
        }
    }
}
