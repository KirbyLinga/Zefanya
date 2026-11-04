<?php

namespace Tests\Feature;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use App\Notifications\Seller\SellerRegistrationDecision;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * H1: every web flow that changes a privileged registration attribute must be
 * exercised end to end. Logistics approval already lives in
 * LogisticsRegistrationTest; this covers the seller queue, which had no
 * approval/rejection test.
 */
class AdminApprovalFlowTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Approval Admin',
            'email' => 'approval'.uniqid().'@example.com',
            'password' => 'AdminSecret123!',
        ]);
    }

    public function test_admin_approves_a_seller_and_the_decision_is_persisted(): void
    {
        Notification::fake();

        $seller = Seller::factory()->pendingApproval()->create();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.registrations.seller.approve', $seller))
            ->assertRedirect();

        $seller->refresh();

        $this->assertSame('approved', $seller->status);
        $this->assertNotNull($seller->approved_at);
        $this->assertNotNull($seller->approved_by);
        Notification::assertSentTo($seller, SellerRegistrationDecision::class);
    }

    public function test_admin_rejects_a_seller_with_a_reason(): void
    {
        Notification::fake();

        $seller = Seller::factory()->pendingApproval()->create();

        $this->actingAs($this->admin(), 'admin')
            ->post(route('admin.registrations.seller.reject', $seller), [
                'rejection_reason' => 'Business permit is unreadable.',
            ])
            ->assertRedirect();

        $seller->refresh();

        $this->assertSame('rejected', $seller->status);
        $this->assertSame('Business permit is unreadable.', $seller->rejection_reason);
        $this->assertNotNull($seller->approved_at);
        $this->assertNotNull($seller->approved_by);
        Notification::assertSentTo($seller, SellerRegistrationDecision::class);
    }

    public function test_a_buyer_cannot_approve_a_seller(): void
    {
        $seller = Seller::factory()->pendingApproval()->create();
        $buyer = Buyer::factory()->create();

        $this->actingAs($buyer, 'buyer')
            ->post(route('admin.registrations.seller.approve', $seller))
            ->assertRedirect(route('admin.login'));

        $this->assertSame('pending_approval', $seller->fresh()->status);
    }
}
