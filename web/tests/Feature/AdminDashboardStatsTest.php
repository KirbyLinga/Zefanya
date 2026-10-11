<?php

namespace Tests\Feature;

use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Admin dashboard summary strip.
 *
 * The stats must come from real queries — the dashboard previously showed a
 * broken `status = 'pending'` count (always 0, because the real statuses are
 * pending_verification / pending_approval / approved / rejected) plus two
 * hardcoded figures ("128" and "₱12,450") that had no backing table at all.
 */
class AdminDashboardStatsTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Stats Admin',
            'email' => 'stats.admin.'.uniqid().'@example.com',
            'password' => Hash::make('password'),
        ]);
    }

    private function dashboard()
    {
        return $this->actingAs($this->admin, 'admin')
            ->get(route('admin.dashboard'));
    }

    /**
     * Seed a known mix of account statuses across all three role tables.
     *
     * Pending (awaiting admin decision) = 5 buyers + 2 sellers + 1 logistics = 8
     * Approved (active accounts)        = 3 buyers + 2 sellers + 1 logistics = 6
     */
    private function seedKnownAccounts(): void
    {
        foreach (range(1, 5) as $i) {
            Buyer::factory()->create(['status' => 'pending_approval', 'approved_at' => null]);
        }
        Buyer::factory()->create(['status' => 'pending_verification', 'email_verified_at' => null, 'approved_at' => null]);
        foreach (range(1, 3) as $i) {
            Buyer::factory()->create(['status' => 'approved']);
        }
        foreach (range(1, 2) as $i) {
            Buyer::factory()->create(['status' => 'rejected']);
        }

        foreach (range(1, 2) as $i) {
            Seller::factory()->pendingApproval()->create();
        }
        foreach (range(1, 2) as $i) {
            Seller::factory()->create(['status' => 'approved']);
        }

        LogisticsProvider::factory()->pendingApproval()->create();
        LogisticsProvider::factory()->create(['status' => 'approved']);
    }

    public function test_pending_registrations_counts_pending_approval_across_every_role(): void
    {
        $this->seedKnownAccounts();

        // 8, not 9: the pending_verification buyer and the two rejected buyers
        // are NOT awaiting an admin decision, so they must not be counted.
        $this->dashboard()
            ->assertOk()
            ->assertSeeInOrder(['>8</span>', 'Pending Registrations'], false);
    }

    public function test_active_users_counts_approved_accounts_across_every_role(): void
    {
        $this->seedKnownAccounts();

        $this->dashboard()
            ->assertOk()
            ->assertSeeInOrder(['>6</span>', 'Active Users'], false);
    }

    public function test_dashboard_shows_no_fabricated_stats(): void
    {
        $this->seedKnownAccounts();

        $response = $this->dashboard()->assertOk();

        // The two hardcoded figures from the mockup must be gone.
        $response->assertDontSee('>128</span>', false);
        $response->assertDontSee('12,450');

        // No disputes table and no commission/revenue source exist yet, so
        // those cards must state "—" instead of inventing a number.
        $response
            ->assertSeeInOrder(['>—</span>', 'Open Disputes'], false)
            ->assertSeeInOrder(['>—</span>', 'Commission (This Month)'], false);
    }
}
