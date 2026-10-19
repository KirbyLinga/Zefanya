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
 * Admin registration queue (reworked registration-management page): the
 * account-type filter (?type=buyer|seller|logistics), the status filter
 * (?status=pending|approved|rejected), the free-text search (?search=…), the
 * date-range + pagination controls, and the stat-card totals rendered above
 * the list.
 */
class AdminRegistrationsFilterTest extends TestCase
{
    use LazilyRefreshDatabase;

    private Admin $admin;

    private Buyer $pendingBuyer;

    private Seller $pendingSeller;

    private LogisticsProvider $pendingLogistics;

    private Buyer $approvedBuyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Queue Admin',
            'email' => 'queue.admin.'.uniqid().'@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->pendingBuyer = Buyer::factory()->create([
            'first_name' => 'Zelda',
            'last_name' => 'Queuewalker',
            'email' => 'zelda.'.uniqid().'@example.com',
            'contact_no' => '09170000001',
            'status' => 'pending',
            'approved_at' => null,
        ]);

        $this->pendingSeller = Seller::factory()->pending()->create([
            'first_name' => 'Marcus',
            'last_name' => 'Queuewright',
            'email' => 'marcus.'.uniqid().'@example.com',
            'business_name' => 'Queue Mart '.uniqid(),
        ]);

        $this->pendingLogistics = LogisticsProvider::factory()->pending()->create([
            'first_name' => 'Rosa',
            'last_name' => 'Queuemover',
            'email' => 'rosa.'.uniqid().'@example.com',
            'business_name' => 'Queue Haulage '.uniqid(),
        ]);

        // Approved applicants must never appear in the default (pending) queue.
        $this->approvedBuyer = Buyer::factory()->create([
            'first_name' => 'Approved',
            'last_name' => 'Queuegone',
            'email' => 'approved.'.uniqid().'@example.com',
            'status' => 'approved',
        ]);
    }

    private function getQueue(array $query = [])
    {
        return $this->actingAs($this->admin, 'admin')
            ->get(route('admin.registrations.index', $query));
    }

    public function test_default_queue_lists_every_pending_registry_and_hides_approved(): void
    {
        $this->getQueue()
            ->assertOk()
            ->assertViewHas('status', 'pending')
            ->assertSee('Zelda Queuewalker')
            ->assertSee('Marcus Queuewright')
            ->assertSee('Rosa Queuemover')
            ->assertDontSee('Approved Queuegone');
    }

    public function test_type_filter_shows_only_the_selected_registry(): void
    {
        $this->getQueue(['type' => 'buyer'])
            ->assertOk()
            ->assertSee('Zelda Queuewalker')
            ->assertDontSee('Marcus Queuewright')
            ->assertDontSee('Rosa Queuemover');
    }

    public function test_type_filter_logistics_shows_only_logistics(): void
    {
        $this->getQueue(['type' => 'logistics'])
            ->assertOk()
            ->assertSee('Rosa Queuemover')
            ->assertDontSee('Zelda Queuewalker')
            ->assertDontSee('Marcus Queuewright');
    }

    public function test_unknown_type_falls_back_to_every_registry(): void
    {
        $this->getQueue(['type' => 'horses'])
            ->assertOk()
            ->assertSee('Zelda Queuewalker')
            ->assertSee('Marcus Queuewright')
            ->assertSee('Rosa Queuemover');
    }

    public function test_search_matches_name_email_contact_and_business_name(): void
    {
        $this->getQueue(['search' => 'Queuewalker'])
            ->assertOk()
            ->assertSee('Zelda Queuewalker')
            ->assertDontSee('Marcus Queuewright');

        $this->getQueue(['search' => $this->pendingSeller->email])
            ->assertOk()
            ->assertSee('Marcus Queuewright')
            ->assertDontSee('Zelda Queuewalker');

        $this->getQueue(['search' => $this->pendingBuyer->contact_no])
            ->assertOk()
            ->assertSee('Zelda Queuewalker')
            ->assertDontSee('Rosa Queuemover');

        // Business names only exist on the seller/logistics tables.
        $this->getQueue(['search' => 'Queue Haulage'])
            ->assertOk()
            ->assertSee('Rosa Queuemover')
            ->assertDontSee('Zelda Queuewalker');
    }

    public function test_search_never_reaches_non_pending_applicants(): void
    {
        $this->getQueue(['search' => 'Queuegone'])
            ->assertOk()
            ->assertSee('No registrations found')
            ->assertDontSee('Approved Queuegone');
    }

    public function test_search_combines_with_the_type_filter(): void
    {
        // The term matches a buyer, but the filter is sellers → nothing.
        $this->getQueue(['type' => 'seller', 'search' => 'Queuewalker'])
            ->assertOk()
            ->assertSee('No registrations found')
            ->assertDontSee('Zelda Queuewalker');

        $this->getQueue(['type' => 'seller', 'search' => 'Queuewright'])
            ->assertOk()
            ->assertSee('Marcus Queuewright')
            ->assertDontSee('Rosa Queuemover');
    }

    public function test_status_filter_lists_approved_applicants(): void
    {
        $this->getQueue(['status' => 'approved'])
            ->assertOk()
            ->assertViewHas('status', 'approved')
            ->assertSee('Approved Queuegone')
            ->assertDontSee('Zelda Queuewalker');
    }

    public function test_empty_queue_shows_the_empty_state(): void
    {
        Seller::query()->delete();
        LogisticsProvider::query()->delete();
        Buyer::query()->where('status', 'pending')->delete();

        $this->getQueue()
            ->assertOk()
            ->assertSee('No registrations found');
    }

    public function test_stat_cards_count_every_status_across_all_registries(): void
    {
        $this->getQueue()
            ->assertOk()
            ->assertViewHas('pendingCount', 3)
            ->assertViewHas('approvedCount', 1)
            ->assertViewHas('rejectedCount', 0);
    }

    public function test_per_page_accepts_the_allowed_sizes_and_rejects_anything_else(): void
    {
        $this->getQueue(['per_page' => 10])
            ->assertOk()
            ->assertViewHas('perPage', 10);

        $this->getQueue(['per_page' => 999])
            ->assertOk()
            ->assertViewHas('perPage', 25);
    }

    public function test_default_date_range_is_all_time_so_old_pending_stays_visible(): void
    {
        // Older than 7 days — still pending, must stay on the default queue
        // (stat cards count it; the list must match).
        $this->pendingBuyer->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->getQueue()
            ->assertOk()
            ->assertViewHas('dateRange', 0)
            ->assertSee('Zelda Queuewalker')
            ->assertSee('Marcus Queuewright');
    }

    public function test_date_range_narrows_the_queue_by_recency(): void
    {
        $this->pendingBuyer->forceFill(['created_at' => now()->subDays(30)])->save();

        $this->getQueue(['date_range' => 7])
            ->assertOk()
            ->assertViewHas('dateRange', 7)
            ->assertDontSee('Zelda Queuewalker')
            ->assertSee('Marcus Queuewright');

        $this->getQueue(['date_range' => 90])
            ->assertOk()
            ->assertSee('Zelda Queuewalker');
    }

    public function test_view_details_button_is_wired_with_applicant_data(): void
    {
        $this->getQueue()
            ->assertOk()
            ->assertSee('data-detail-name="Zelda Queuewalker"', false)
            ->assertSee('openDetailModal(this)', false)
            ->assertSee('id="detailModal"', false);
    }
}
