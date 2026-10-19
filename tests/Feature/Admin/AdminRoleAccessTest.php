<?php

namespace Tests\Feature;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    private function adminWithRole(string $role): Admin
    {
        return Admin::factory()->create(['role' => $role]);
    }

    public function test_guests_are_redirected_to_admin_login(): void
    {
        $this->get(route('admin.registrations.index'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_allowed_roles_can_open_the_registration_queue(): void
    {
        foreach (['super_admin', 'admin', 'moderator'] as $role) {
            $this->actingAs($this->adminWithRole($role), 'admin')
                ->get(route('admin.registrations.index'))
                ->assertOk();
        }
    }

    public function test_support_role_is_forbidden_from_the_registration_queue(): void
    {
        $this->actingAs($this->adminWithRole('support'), 'admin')
            ->get(route('admin.registrations.index'))
            ->assertForbidden();
    }

    public function test_support_role_is_forbidden_from_approving_registrations(): void
    {
        $buyer = Buyer::factory()->create([
            'status' => 'pending',
            'approved_at' => null,
        ]);

        $this->actingAs($this->adminWithRole('support'), 'admin')
            ->post(route('admin.registrations.buyer.approve', $buyer))
            ->assertForbidden();

        $this->assertSame('pending', $buyer->fresh()->status);
    }

    public function test_support_role_is_forbidden_from_private_documents(): void
    {
        $this->actingAs($this->adminWithRole('support'), 'admin')
            ->get(route('admin.documents.stream', [
                'type' => 'seller',
                'id' => 1,
                'document' => 'id',
            ]))
            ->assertForbidden();
    }

    public function test_every_admin_role_can_open_the_dashboard(): void
    {
        foreach (['super_admin', 'admin', 'moderator', 'support'] as $role) {
            $this->actingAs($this->adminWithRole($role), 'admin')
                ->get(route('admin.dashboard'))
                ->assertOk();
        }
    }
}
