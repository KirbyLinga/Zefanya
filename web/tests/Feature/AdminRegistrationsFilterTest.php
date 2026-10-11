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
 * Admin registration queue: the registry filter (?filter=buyer|seller|logistics),
 * the free-text search (?q=…) and the legacy detail-selection params
 * (?view=&type=) which must keep working alongside both.
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
            'status' => 'pending_approval',
            'approved_at' => null,
        ]);

        $this->pendingSeller = Seller::factory()->pendingApproval()->create([
            'first_name' => 'Marcus',
            'last_name' => 'Queuewright',
            'email' => 'marcus.'.uniqid().'@example.com',
            'business_name' => 'Queue Mart '.uniqid(),
        ]);

        $this->pendingLogistics = LogisticsProvider::factory()->pendingApproval()->create([
            'first_name' => 'Rosa',
            'last_name' => 'Queuemover',
            'email' => 'rosa.'.uniqid().'@example.com',
            'business_name' => 'Queue Haulage '.uniqid(),
        ]);

        // Approved applicants must never appear in the pending queue.
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
            ->assertSee('Zelda Queuewalker')
            ->assertSee('Marcus Queuewright')
            ->assertSee('Rosa Queuemover')
            ->assertDontSee('Approved Queuegone');
    }

    public function test_filter_shows_only_the_selected_registry(): void
    {
        $this->getQueue(['filter' => 'buyer'])
            ->assertOk()
            ->assertSee('Zelda Queuewalker')
            ->assertDontSee('Marcus Queuewright')
            ->assertDontSee('Rosa Queuemover');
    }

    public function test_filter_logistics_shows_only_logistics(): void
    {
        $this->getQueue(['filter' => 'logistics'])
            ->assertOk()
            ->assertSee('Rosa Queuemover')
            ->assertDontSee('Zelda Queuewalker')
            ->assertDontSee('Marcus Queuewright');
    }

    public function test_unknown_filter_falls_back_to_every_registry(): void
    {
        $this->getQueue(['filter' => 'horses'])
            ->assertOk()
            ->assertSee('Zelda Queuewalker')
            ->assertSee('Marcus Queuewright')
            ->assertSee('Rosa Queuemover');
    }

    public function test_search_matches_name_email_contact_and_business_name(): void
    {
        $this->getQueue(['q' => 'Queuewalker'])
            ->assertOk()
            ->assertSee('Zelda Queuewalker')
            ->assertDontSee('Marcus Queuewright');

        $this->getQueue(['q' => $this->pendingSeller->email])
            ->assertOk()
            ->assertSee('Marcus Queuewright')
            ->assertDontSee('Zelda Queuewalker');

        $this->getQueue(['q' => $this->pendingBuyer->contact_no])
            ->assertOk()
            ->assertSee('Zelda Queuewalker')
            ->assertDontSee('Rosa Queuemover');

        // Business names only exist on the seller/logistics tables.
        $this->getQueue(['q' => 'Queue Haulage'])
            ->assertOk()
            ->assertSee('Rosa Queuemover')
            ->assertDontSee('Zelda Queuewalker');
    }

    public function test_search_never_reaches_non_pending_applicants(): void
    {
        $this->getQueue(['q' => 'Queuegone'])
            ->assertOk()
            ->assertSee('No pending registrations match')
            ->assertDontSee('Approved Queuegone');
    }

    public function test_search_combines_with_the_registry_filter(): void
    {
        // The term matches a buyer, but the filter is sellers → nothing.
        $this->getQueue(['filter' => 'seller', 'q' => 'Queuewalker'])
            ->assertOk()
            ->assertSee('No pending registrations match')
            ->assertDontSee('Zelda Queuewalker');

        $this->getQueue(['filter' => 'seller', 'q' => 'Queuewright'])
            ->assertOk()
            ->assertSee('Marcus Queuewright')
            ->assertDontSee('Rosa Queuemover');
    }

    public function test_empty_registry_filter_shows_its_own_empty_message(): void
    {
        Seller::query()->delete();

        $this->getQueue(['filter' => 'seller'])
            ->assertOk()
            ->assertSee('No pending seller registrations right now.');
    }

    public function test_chips_count_the_matches_per_registry_under_the_search(): void
    {
        $this->getQueue()
            ->assertOk()
            ->assertViewHas('counts', fn (array $counts) => $counts === [
                'buyer' => 1,
                'seller' => 1,
                'logistics' => 1,
            ])
            // The "All" chip renders the summed total as its badge (1+1+1).
            ->assertSee('>3</span>', false);

        $this->getQueue(['q' => 'Queuewright'])
            ->assertOk()
            ->assertViewHas('counts', fn (array $counts) => $counts['buyer'] === 0
                && $counts['seller'] === 1
                && $counts['logistics'] === 0);
    }

    public function test_search_box_keeps_the_filter_and_list_links_carry_both_params(): void
    {
        $this->getQueue(['filter' => 'seller', 'q' => 'Queuewright'])
            ->assertOk()
            // Hidden input re-submits the active registry with the new term.
            ->assertSee('name="filter"', false)
            ->assertSee('value="seller"', false)
            // Every applicant link keeps both params so the next click
            // doesn't drop the admin back to the unfiltered queue.
            ->assertSee('filter=seller', false)
            ->assertSee('q=Queuewright', false);
    }

    public function test_detail_panel_still_opens_with_filter_and_search_active(): void
    {
        $this->getQueue([
            'filter' => 'buyer',
            'q' => 'Queuewalker',
            'view' => $this->pendingBuyer->id,
            'type' => 'buyer',
        ])
            ->assertOk()
            ->assertSee($this->pendingBuyer->email)
            ->assertSee('Zelda Queuewalker');
    }
}
