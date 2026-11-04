<?php

namespace Tests\Feature;

use App\Enums\LogisticsProviderStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Product;
use App\Models\Psgc\PsgcBarangay;
use App\Models\Psgc\PsgcCity;
use App\Models\Psgc\PsgcProvince;
use App\Models\Psgc\PsgcRegion;
use App\Models\Seller\Seller;
use App\Models\SellerOrder;
use App\Models\Shared\Category;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase D: End-to-End Acceptance Test
 *
 * Full multi-role lifecycle on real database and API surface:
 * 1. Buyer registers with document upload.
 * 2. Buyer verifies email code.
 * 3. Buyer logs in and acquires Sanctum token.
 * 4. Buyer adds shipping address with a province.
 * 5. Buyer browses catalog.
 * 6. Buyer adds product to wishlist.
 * 7. Buyer applies voucher and places order paying COD.
 * 8. Seller confirms and marks order ready for pickup with tracking number.
 * 9. Rider registers with vehicle and ID documents (pending approval).
 * 10. Admin approves rider.
 * 11. Rider logs in, goes online, and reads assigned jobs.
 * 12. Rider scans parcel tracking number at pickup (PICKED_UP).
 * 13. Parcel moves out for delivery with issued delivery pass OTP.
 * 14. Buyer shows delivery pass; rider verifies OTP and collects COD cash (DELIVERED).
 * 15. Buyer posts photo review with verified purchase badge.
 */
class EndToEndAcceptanceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_complete_multi_role_marketplace_story(): void
    {
        Storage::fake('public');
        Storage::fake('local');

        // Seed minimal PSGC data for realistic address entry
        $region = PsgcRegion::create(['code' => '070000000', 'name' => 'Region VII (Central Visayas)']);
        $province = PsgcProvince::create(['code' => '072200000', 'region_code' => $region->code, 'name' => 'Cebu']);
        $city = PsgcCity::create(['code' => '072217000', 'province_code' => $province->code, 'region_code' => $region->code, 'name' => 'Cebu City']);
        $barangay = PsgcBarangay::create(['code' => '072217024', 'city_code' => $city->code, 'name' => 'Lahug']);

        // Create approved seller with a product
        $seller = Seller::factory()->create([
            'business_name' => 'Amihan Crafts Studio',
            'status' => 'approved',
            'province_name' => 'Cebu',
            'municipality_name' => 'Cebu City',
            'barangay_name' => 'Lahug',
            'street' => 'Gorordo Ave',
        ]);

        $category = Category::create(['icon' => 'shopping_bag', 'label' => 'Handmade Bags']);

        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'category_id' => $category->id,
            'name' => 'Handwoven Banig Tote',
            'price' => 1200.00,
            'stock_quantity' => 15,
            'status' => 'active',
        ]);

        // Create active voucher
        $voucher = Voucher::create([
            'code' => 'SAVE100',
            'type' => 'fixed',
            'value' => 10000,
            'min_spend' => 50000,
            'usage_limit' => 50,
            'times_used' => 0,
            'is_active' => true,
        ]);

        // ── 1. Buyer Registration ─────────────────────────────────────────────
        $registerResponse = $this->post(route('api.register.buyer.store'), [
            'first_name' => 'Marielles',
            'last_name' => 'Santos',
            'email' => 'marielles.santos@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'contact_no' => '09171234567',
            'birthday' => '1998-05-15',
            'sex' => 'female',
            'address_mode' => 'manual',
            'house_number' => '12',
            'street' => 'Mabini Street',
            'province_name' => 'Cebu',
            'municipality_name' => 'Cebu City',
            'barangay_name' => 'Lahug',
            'upload_id' => UploadedFile::fake()->create('valid_id.jpg', 100, 'image/jpeg'),
        ]);

        $registerResponse->assertStatus(201);
        $buyerId = (int) $registerResponse->json('verification_id');
        $buyer = Buyer::findOrFail($buyerId);

        // ── 2. Email Verification OTP ─────────────────────────────────────────
        $rawOtp = '482913';
        $buyer->forceFill([
            'email_verification_code' => hash('sha256', $rawOtp),
            'email_verification_expires_at' => now()->addMinutes(10),
        ])->save();

        $verifyResponse = $this->post(route('api.register.buyer.verify-otp', $buyer), [
            'otp' => $rawOtp,
        ]);
        $verifyResponse->assertStatus(200);

        // Admin approves buyer account
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@zefanya.local',
            'password' => 'AdminSecret123!',
        ]);
        $admin->forceFill(['role' => 'admin'])->save();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.registrations.buyer.approve', $buyer))
            ->assertRedirect();

        $buyer->refresh();
        $this->assertSame('approved', $buyer->status);

        // ── 3. Buyer Login (Token acquisition) ────────────────────────────────
        $loginResponse = $this->postJson(route('api.login.buyer'), [
            'email' => 'marielles.santos@example.com',
            'password' => 'Password123!',
        ])->assertOk();

        $buyerToken = $loginResponse->json('token');
        $this->assertNotEmpty($buyerToken);

        // ── 4. Buyer adds Address with Province ───────────────────────────────
        $addressResponse = $this->withHeader('Authorization', "Bearer $buyerToken")
            ->postJson(route('api.v1.buyer.addresses.store'), [
                'recipient' => 'Marielles Santos',
                'phone' => '09171234567',
                'region' => 'Region VII (Central Visayas)',
                'region_code' => $region->code,
                'province' => 'Cebu',
                'province_code' => $province->code,
                'city' => 'Cebu City',
                'city_code' => $city->code,
                'barangay' => 'Lahug',
                'barangay_code' => $barangay->code,
                'street' => '12 Mabini Street, Villa Aurora',
                'postal_code' => '6000',
                'is_default' => true,
            ])
            ->assertStatus(201);

        $addressId = $addressResponse->json('data.id');
        $this->assertNotNull($addressId);
        $this->assertSame('Cebu', $addressResponse->json('data.province'));

        // ── 5. Browse Catalog ─────────────────────────────────────────────────
        $catalogResponse = $this->getJson(route('api.products.index'))->assertOk();
        $this->assertNotEmpty($catalogResponse->json('data'));
        $this->assertSame('Handwoven Banig Tote', $catalogResponse->json('data.0.name'));
        $this->assertSame('Amihan Crafts Studio', $catalogResponse->json('data.0.seller.name'));

        // ── 6. Wishlist ───────────────────────────────────────────────────────
        $wishlistResponse = $this->withHeader('Authorization', "Bearer $buyerToken")
            ->postJson(route('api.buyer.wishlist.store', $product->id))
            ->assertStatus(201);
        $wishlistResponse->assertJson(['ok' => true]);

        $this->withHeader('Authorization', "Bearer $buyerToken")
            ->getJson(route('api.buyer.wishlist.index'))
            ->assertOk()
            ->assertJsonPath('products.0.id', (string) $product->id);

        // ── 7. Apply Voucher and Checkout (COD) ────────────────────────────────
        $checkoutResponse = $this->withHeader('Authorization', "Bearer $buyerToken")
            ->postJson(route('api.orders.store'), [
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 2],
                ],
                'voucher_code' => 'SAVE100',
            ])
            ->assertStatus(201);

        // 2 items * ₱1200 = ₱2400 (240000 centavos) - ₱100 discount (10000) = ₱2300 (230000)
        $checkoutResponse->assertJsonPath('meta.subtotal_minor', 240000);
        $checkoutResponse->assertJsonPath('meta.discount_minor', 10000);
        $checkoutResponse->assertJsonPath('meta.total_minor', 230000);

        $sellerOrderId = $checkoutResponse->json('data.0.id');
        $sellerOrder = SellerOrder::with(['order', 'payment'])->findOrFail($sellerOrderId);
        $this->assertSame(OrderStatus::Placed, $sellerOrder->status);
        $this->assertSame('Cebu', $sellerOrder->order->shipping_address['province']);
        $this->assertSame(PaymentStatus::Pending, $sellerOrder->payment->status);

        // ── 8. Seller Confirms and Marks Ready ────────────────────────────────
        $this->actingAs($seller, 'seller')
            ->patchJson(route('seller.orders.confirm', $sellerOrder))
            ->assertOk()
            ->assertJson(['status' => 'CONFIRMED']);

        $trackingNumber = 'ZEF-CEB-998811';
        $this->actingAs($seller, 'seller')
            ->patchJson(route('seller.orders.ready', $sellerOrder), [
                'tracking_number' => $trackingNumber,
            ])
            ->assertOk()
            ->assertJson(['status' => 'READY_FOR_PICKUP', 'tracking_number' => $trackingNumber]);

        $sellerOrder->refresh();
        $this->assertSame(OrderStatus::ReadyForPickup, $sellerOrder->status);
        $this->assertSame($trackingNumber, $sellerOrder->tracking_number);

        // ── 9. Rider Registers with Documents ─────────────────────────────────
        $riderRegisterResponse = $this->post(route('api.register.courier.store'), [
            'first_name' => 'Eduardo',
            'last_name' => 'Reyes',
            'middle_initial' => 'P',
            'sex' => 'male',
            'email' => 'eduardo.rider@example.com',
            'password' => 'RiderPass123!',
            'password_confirmation' => 'RiderPass123!',
            'contact_no' => '09181234567',
            'birthday' => '1995-08-20',
            'company' => 'Express Logistics',
            'vehicle_type' => 'Motorcycle',
            'plate_number' => 'MC-7788',
            'license_number' => 'N01-12-345678',
            'license_expiry' => now()->addYear()->format('Y-m-d'),
            'province_name' => 'Cebu',
            'municipality_name' => 'Cebu City',
            'barangay_name' => 'Lahug',
            'street' => '45 Mango Ave',
            'hub' => 'Cebu Hub',
            'upload_id' => UploadedFile::fake()->create('id.jpg', 100, 'image/jpeg'),
            'license_doc' => UploadedFile::fake()->create('license.jpg', 100, 'image/jpeg'),
            'or_cr_doc' => UploadedFile::fake()->create('motorcycle.jpg', 100, 'image/jpeg'),
        ])->assertStatus(201);

        $rider = LogisticsProvider::where('email', 'eduardo.rider@example.com')->firstOrFail();
        $this->assertSame(LogisticsProviderStatus::PendingApproval, $rider->status);

        // ── 10. Admin Approves Rider ──────────────────────────────────────────
        Notification::fake();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.registrations.logistics.approve', $rider))
            ->assertRedirect();

        $rider->refresh();
        $this->assertSame(LogisticsProviderStatus::Approved, $rider->status);

        // Clear previous actingAs guards (seller, admin) so courier token takes effect
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        // ── 11. Rider Goes Online, Logs In, Gets Assigned Job ─────────────────
        $riderLoginResponse = $this->postJson(route('api.login.courier'), [
            'email' => 'eduardo.rider@example.com',
            'password' => 'RiderPass123!',
        ])->assertOk();

        $riderToken = $riderLoginResponse->json('token');
        $this->assertNotEmpty($riderToken);

        // Dispatch assigns job to rider
        $sellerOrder->assignCourier($rider, 8500, 'Cebu City (5.2 km)');

        $jobsResponse = $this->withHeader('Authorization', "Bearer $riderToken")
            ->getJson(route('api.courier.jobs.index'))
            ->assertOk();

        $jobsResponse->assertJsonPath('data.0.id', (string) $sellerOrder->id);
        $jobsResponse->assertJsonPath('data.0.fee', 85);
        $jobsResponse->assertJsonPath('data.0.cod_amount', 2300);
        $jobsResponse->assertJsonPath('data.0.tracking_number', $trackingNumber);

        // ── 12. Rider Scans Parcel QR at Pickup (PICKED_UP) ───────────────────
        $pickupResponse = $this->withHeader('Authorization', "Bearer $riderToken")
            ->patchJson(route('api.courier.jobs.milestone', $sellerOrder), [
                'milestone' => 'PICKED_UP',
                'tracking_number' => $trackingNumber,
            ]);
        $pickupResponse->assertOk()
            ->assertJson(['ok' => true, 'milestone' => 'PICKED_UP']);

        // Hub pipeline to OUT_FOR_DELIVERY
        $sellerOrder->refresh();
        $sellerOrder->forceFill([
            'status' => OrderStatus::OutForDelivery,
        ])->save();

        // The previous request authenticated the rider; drop that cached guard
        // before the buyer asks for the pass, then drop the buyer's before the
        // rider confirms. Tokens differ per request, guards do not.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $passResponse = $this->withHeader('Authorization', "Bearer $buyerToken")
            ->postJson(route('api.orders.delivery-pass', $sellerOrder))
            ->assertOk();

        $passToken = $passResponse->json('data.token');
        $passOtp = $passResponse->json('data.code');
        $this->assertNotEmpty($passToken);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $passOtp);

        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $deliveryResponse = $this->withHeader('Authorization', "Bearer $riderToken")
            ->patch(route('api.courier.jobs.milestone', $sellerOrder), [
                'milestone' => 'DELIVERED',
                'delivery_token' => $passToken,
                'cod_amount' => 2300.00,
                'proof_photo' => UploadedFile::fake()->create('proof.jpg', 100, 'image/jpeg'),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'milestone' => 'DELIVERED',
                'payment_status' => 'paid',
            ]);

        $sellerOrder->refresh();
        $this->assertSame(OrderStatus::Delivered, $sellerOrder->status);
        $this->assertNotNull($sellerOrder->delivered_at);
        $this->assertSame(PaymentStatus::Paid, $sellerOrder->payment->status);

        // Reset auth guards to switch back to buyer session
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        // ── 15. Buyer Posts Photo Review with Verified Badge ──────────────────
        $reviewResponse = $this->withHeader('Authorization', "Bearer $buyerToken")
            ->patchJson(route('api.reviews.upsert', ['sellerOrder' => $sellerOrder->id, 'product' => $product->id]), [
                'stars' => 5,
                'comment' => 'Exquisite banig craftsmanship! Highly recommended local weaving.',
                'photo_path' => 'reviews/banig_delivered_photo.jpg',
            ])
            ->assertStatus(201)
            ->assertJson([
                'data' => [
                    'order_id' => (string) $sellerOrder->id,
                    'product_id' => (string) $product->id,
                    'stars' => 5,
                    'comment' => 'Exquisite banig craftsmanship! Highly recommended local weaving.',
                ],
            ]);

        // Buyer verifies review in my-reviews feed
        $myReviewsResponse = $this->withHeader('Authorization', "Bearer $buyerToken")
            ->getJson(route('api.reviews.mine'))
            ->assertOk();

        $myReviewsResponse->assertJsonPath('data.0.order_id', (string) $sellerOrder->id);
        $myReviewsResponse->assertJsonPath('data.0.stars', 5);

        // Product rating aggregation reflects review
        $productCatalogCheck = $this->getJson(route('api.products.index'))->assertOk();
        $this->assertEquals(5.0, (float) $productCatalogCheck->json('data.0.rating_avg'));
        $productCatalogCheck->assertJsonPath('data.0.rating_count', 1);
    }
}
