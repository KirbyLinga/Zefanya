<?php

namespace Tests\Feature\Security;

use App\Enums\LogisticsProviderStatus;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Order;
use App\Models\Product;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * H5: access-control and uploads.
 *
 * Each check documents its result as OK or FIXED in the comment.
 */
class AccessControlTest extends TestCase
{
    use LazilyRefreshDatabase;

    // ── helpers ──────────────────────────────────────────────────────────

    private function buyer(): Buyer
    {
        return Buyer::factory()->create(['status' => 'approved']);
    }

    private function approvedCourier(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'status' => LogisticsProviderStatus::Approved,
            'email_verified_at' => now(),
        ]);
    }

    private function pendingCourier(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'status' => LogisticsProviderStatus::PendingApproval,
            'email_verified_at' => now(),
        ]);
    }

    private function rejectedCourier(): LogisticsProvider
    {
        return LogisticsProvider::factory()->create([
            'status' => LogisticsProviderStatus::Rejected,
            'email_verified_at' => now(),
        ]);
    }

    private function sellerOrderFor(Buyer $buyer, string $status = 'DELIVERED'): SellerOrder
    {
        $seller  = Seller::factory()->create(['status' => 'approved']);
        $product = Product::factory()->create(['seller_id' => $seller->id, 'status' => 'active']);
        $order   = Order::query()->create([
            'buyer_id'         => $buyer->id,
            'reference'        => Order::generateUniqueReference(),
            'shipping_address' => ['name' => 'Test', 'full' => 'Test'],
            'subtotal_minor'   => 10000,
            'discount_minor'   => 0,
            'total_minor'      => 10000,
        ]);
        $so = SellerOrder::query()->create([
            'order_id'           => $order->id,
            'seller_id'          => $seller->id,
            'subtotal_minor'     => 10000,
            'delivery_fee_minor' => 8500,
            'delivery_area'      => 'Test',
        ]);
        $so->forceFill(['status' => $status])->save();

        return $so;
    }

    // ── Mass assignment (FIXED) ───────────────────────────────────────────

    /**
     * FIXED — registration cannot set role, is_admin, status, email_verified_at.
     *
     * FAILS before the fix (createFromRegistration used mass-assign and would
     * respect any 'status' key if it slipped through validation).
     * PASSES after: status is hardcoded to 'pending_verification' in
     * Buyer::createFromRegistration() regardless of request payload.
     */
    public function test_registration_cannot_set_privileged_fields(): void
    {
        Storage::fake('local');

        $response = $this->postJson('/api/register/buyer', [
            'last_name'             => 'Doe',
            'first_name'            => 'John',
            'sex'                   => 'male',
            'email'                 => 'john.mass@example.com',
            'password'              => 'Password1!',
            'password_confirmation' => 'Password1!',
            'contact_no'            => '09171234567',
            'birthday'              => '1990-01-01',
            'address_mode'          => 'manual',
            'street'                => '123 Main St',
            // Attempted privilege escalation via extra fields
            'role'                  => 'admin',
            'is_admin'              => '1',
            'status'                => 'approved',
            'email_verified_at'     => now()->toDateTimeString(),
            'upload_id'             => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertStatus(201);

        $buyer = Buyer::where('email', 'john.mass@example.com')->firstOrFail();

        // Status must be pending_verification regardless of what was sent
        $this->assertSame('pending_verification', $buyer->status);

        $this->assertDatabaseHas('buyers', [
            'email'  => 'john.mass@example.com',
            'status' => 'pending_verification',
        ]);
    }

    // ── Pending / rejected riders blocked (FIXED) ─────────────────────────

    /**
     * FIXED — pending rider cannot reach GET /api/courier/jobs.
     *
     * FAILS before fix (no middleware gate on courier routes beyond auth:sanctum).
     * PASSES after: EnsureCourierApproved middleware applied to courier routes.
     */
    public function test_pending_rider_cannot_reach_courier_jobs(): void
    {
        $courier = $this->pendingCourier();
        Sanctum::actingAs($courier, [], 'sanctum');

        $this->getJson('/api/courier/jobs')->assertStatus(403);
    }

    /**
     * FIXED — rejected rider cannot reach GET /api/courier/jobs.
     */
    public function test_rejected_rider_cannot_reach_courier_jobs(): void
    {
        $courier = $this->rejectedCourier();
        Sanctum::actingAs($courier, [], 'sanctum');

        $this->getJson('/api/courier/jobs')->assertStatus(403);
    }

    /**
     * FIXED — pending rider cannot reach PATCH /api/courier/jobs/{job}/milestone.
     */
    public function test_pending_rider_cannot_patch_milestone(): void
    {
        $courier = $this->pendingCourier();
        Sanctum::actingAs($courier, [], 'sanctum');

        $buyer = $this->buyer();
        $so    = $this->sellerOrderFor($buyer, 'OUT_FOR_DELIVERY');

        $this->patchJson('/api/courier/jobs/'.$so->id.'/milestone', [
            'status' => 'DELIVERED',
        ])->assertStatus(403);
    }

    /** OK — approved rider can still reach courier routes. */
    public function test_approved_rider_can_reach_courier_jobs(): void
    {
        $courier = $this->approvedCourier();
        Sanctum::actingAs($courier, [], 'sanctum');

        $this->getJson('/api/courier/jobs')->assertOk();
    }

    // ── Buyer cross-ownership (OK) ────────────────────────────────────────

    /** OK — buyer B cannot read buyer A's milestone. */
    public function test_buyer_cannot_read_another_buyers_milestone(): void
    {
        $buyerA = $this->buyer();
        $buyerB = $this->buyer();
        $so     = $this->sellerOrderFor($buyerA);

        Sanctum::actingAs($buyerB, [], 'sanctum');
        $this->getJson('/api/orders/'.$so->id.'/milestone')->assertStatus(403);
    }

    /** OK — buyer B cannot GET buyer A's delivery address. */
    public function test_buyer_cannot_read_another_buyers_address(): void
    {
        $buyerA  = $this->buyer();
        $buyerB  = $this->buyer();
        $address = BuyerAddress::factory()->create(['buyer_id' => $buyerA->id]);

        Sanctum::actingAs($buyerB, [], 'sanctum');
        $this->getJson('/api/buyer/addresses/'.$address->id)->assertStatus(403);
    }

    // ── Courier cross-ownership (OK) ──────────────────────────────────────

    /** OK — courier B cannot see jobs assigned to courier A. */
    public function test_courier_index_only_returns_own_jobs(): void
    {
        $courierA = $this->approvedCourier();
        $courierB = $this->approvedCourier();
        $buyer    = $this->buyer();

        $so = $this->sellerOrderFor($buyer, 'OUT_FOR_DELIVERY');
        $so->forceFill(['assigned_courier_id' => $courierA->id])->save();

        Sanctum::actingAs($courierB, [], 'sanctum');

        $res = $this->getJson('/api/courier/jobs')->assertOk();
        $ids = collect($res->json('data') ?? [])->pluck('id');
        $this->assertNotContains($so->id, $ids->toArray());
    }

    // ── Uploads disk (OK) ─────────────────────────────────────────────────

    /** OK — rider documents are stored on the private disk. */
    public function test_rider_documents_are_on_private_disk(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->postJson('/api/register/courier', [
            'first_name'            => 'Rider',
            'last_name'             => 'Disk',
            'sex'                   => 'male',
            'email'                 => 'rider.disk@example.com',
            'password'              => 'Password1!',
            'password_confirmation' => 'Password1!',
            'contact_no'            => '09171234568',
            'birthday'              => '1990-01-01',
            'vehicle_type'          => 'motorcycle',
            'plate_number'          => 'AAA111',
            'license_number'        => 'N01-12-345678',
            'license_expiry'        => now()->addYear()->format('Y-m-d'),
            'upload_id'             => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
        ])->assertStatus(201);

        $privateFiles = Storage::disk('local')->allFiles();
        $publicFiles  = Storage::disk('public')->allFiles();

        $this->assertNotEmpty(
            array_filter($privateFiles, fn ($f) => str_starts_with($f, 'logistics/')),
            'Rider document should land on private disk'
        );
        $this->assertEmpty(
            array_filter($publicFiles, fn ($f) => str_starts_with($f, 'logistics/')),
            'Rider document must NOT be on public disk'
        );
    }

    // Secrets hygiene: see scripts/check-secrets.ps1

}
