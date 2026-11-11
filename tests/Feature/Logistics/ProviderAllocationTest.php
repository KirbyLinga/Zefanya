<?php

namespace Tests\Feature\Logistics;

use App\Models\Admin\Admin;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\ParcelProviderAllocation;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\ProviderAllocationService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** I-04 — 3+ provider chains get audit records without amounts until an Admin approves a policy. */
class ProviderAllocationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function parcel(): SellerOrder
    {
        return SellerOrder::factory()->create([
            'order_id' => Order::factory()->create()->id,
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->id,
        ]);
    }

    /** @param list<int> $chain */
    private function service(array $chain): ProviderAllocationService
    {
        return new class($chain) extends ProviderAllocationService
        {
            public function __construct(private array $ids) {}

            public function chain(SellerOrder $parcel): array
            {
                return $this->ids;
            }
        };
    }

    public function test_two_provider_parcels_get_no_allocation_records(): void
    {
        $ids = LogisticsProvider::factory()->count(2)->create()->pluck('id')->all();

        $this->assertSame(0, $this->service($ids)->prepare($this->parcel()));
        $this->assertSame(0, ParcelProviderAllocation::query()->count());
    }

    public function test_three_provider_parcels_get_one_amountless_record_each_and_repeat_safely(): void
    {
        $ids = LogisticsProvider::factory()->count(3)->create()->pluck('id')->all();
        $parcel = $this->parcel();
        $service = $this->service($ids);

        $this->assertSame(3, $service->prepare($parcel));
        $this->assertSame(0, $service->prepare($parcel));

        $rows = ParcelProviderAllocation::query()->orderBy('sequence')->get();
        $this->assertSame(['origin', 'intermediate', 'destination'], $rows->pluck('role')->all());
        $this->assertSame([null, null, null], $rows->pluck('share_minor')->all());
        $this->assertSame(['pending_policy'], $rows->pluck('status')->unique()->all());
    }

    public function test_payouts_stay_disabled_until_an_admin_approves_a_policy(): void
    {
        $service = new ProviderAllocationService;
        $this->assertFalse($service->payoutsEnabled());

        $moderator = Admin::factory()->create(['role' => 'moderator']);
        try {
            $service->approvePolicy($moderator, 'Intermediate providers share a fixed portion of the fee.');
            $this->fail('Moderator approved a policy.');
        } catch (HttpException|HttpResponseException $e) {
            $this->assertSame(403, $e instanceof HttpException ? $e->getStatusCode() : 403);
        }
        $this->assertFalse($service->payoutsEnabled());

        $service->approvePolicy(Admin::factory()->create(['role' => 'admin']), 'Intermediate providers share a fixed portion of the fee.');
        $this->assertTrue($service->payoutsEnabled());
    }

    public function test_policy_route_rejects_moderators(): void
    {
        $moderator = Admin::factory()->create(['role' => 'moderator']);

        $this->actingAs($moderator, 'admin')
            ->post(route('admin.logistics-remittances.allocation-policy'), ['policy_note' => str_repeat('a', 30)])
            ->assertForbidden();
    }
}
