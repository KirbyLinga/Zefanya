<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use App\Notifications\Buyer\BuyerRegistrationDecision;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The routed admin approval/rejection endpoints must persist the audit fields
 * (approved_by / approved_at / rejection_reason) for every registry. Those
 * columns are controller-owned and absent from Buyer::$fillable and
 * Seller::$fillable, so a regression to Model::update() would silently drop
 * them — these tests lock the audit trail in.
 */
class AdminRegistrationAuditTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    private function admin(): Admin
    {
        return Admin::create([
            'name' => 'Audit Admin',
            'email' => 'audit'.uniqid().'@zefanya.shop',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    public function test_approving_a_buyer_records_the_reviewing_admin_and_timestamp(): void
    {
        $admin = $this->admin();
        $buyer = Buyer::factory()->create(['status' => 'pending']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.registrations.buyer.approve', $buyer))
            ->assertRedirect();

        $buyer->refresh();

        $this->assertSame('approved', $buyer->status);
        $this->assertSame($admin->id, $buyer->approved_by);
        $this->assertNotNull($buyer->approved_at);

        // Approve/reject must be mail — applicants cannot open the in-app bell yet.
        Notification::assertSentTo($buyer, BuyerRegistrationDecision::class, function (BuyerRegistrationDecision $n, array $channels): bool {
            return $n->decision === 'approved' && $channels === ['mail'];
        });
    }

    public function test_rejecting_a_buyer_records_the_reason_and_reviewer(): void
    {
        $admin = $this->admin();
        $buyer = Buyer::factory()->create(['status' => 'pending']);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.registrations.buyer.reject', $buyer), ['rejection_reason' => 'Blurred ID'])
            ->assertRedirect();

        $buyer->refresh();

        $this->assertSame('rejected', $buyer->status);
        $this->assertSame($admin->id, $buyer->approved_by);
        $this->assertNotNull($buyer->approved_at);
        $this->assertSame('Blurred ID', $buyer->rejection_reason);
    }

    public function test_approving_a_seller_records_the_reviewing_admin_and_timestamp(): void
    {
        $admin = $this->admin();
        $seller = Seller::factory()->pending()->create();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.registrations.seller.approve', $seller))
            ->assertRedirect();

        $seller->refresh();

        $this->assertSame('approved', $seller->status);
        $this->assertSame($admin->id, $seller->approved_by);
        $this->assertNotNull($seller->approved_at);
    }

    public function test_rejecting_a_seller_records_the_reason_and_reviewer(): void
    {
        $admin = $this->admin();
        $seller = Seller::factory()->pending()->create();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.registrations.seller.reject', $seller), ['rejection_reason' => 'Incomplete DTI permit'])
            ->assertRedirect();

        $seller->refresh();

        $this->assertSame('rejected', $seller->status);
        $this->assertSame($admin->id, $seller->approved_by);
        $this->assertNotNull($seller->approved_at);
        $this->assertSame('Incomplete DTI permit', $seller->rejection_reason);
    }
}
