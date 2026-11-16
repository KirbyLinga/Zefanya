<?php

namespace Tests\Feature\Seller;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Buyer\Buyer;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\OrderItem;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\WaybillQrCode;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/**
 * F2 Stage 1 — printable SVG QR on the seller waybill encodes only the
 * opaque tracking reference.
 */
class WaybillQrCodeTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * @return array{0: Seller, 1: SellerOrder, 2: Buyer, 3: string}
     */
    private function shipmentWithTracking(?string $tracking = 'TRK-20261021-ABC123'): array
    {
        $buyer = Buyer::factory()->create([
            'status' => 'approved',
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'contact_no' => '09171234567',
        ]);
        $seller = Seller::factory()->create(['status' => 'approved']);

        $product = Product::factory()->create([
            'seller_id' => $seller->id,
            'status' => 'active',
        ]);

        $order = Order::factory()->create([
            'buyer_id' => $buyer->id,
            'status' => OrderStatus::Placed,
            'shipping_address' => [
                'name' => 'Maria Santos',
                'full' => '123 Secret Street, Quezon City',
                'phone' => '09171234567',
                'barangay' => 'Holy Spirit',
                'municipality' => 'Quezon City',
                'province' => 'Metro Manila',
                'postal_code' => '1127',
            ],
        ]);

        $sellerOrder = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => $seller->id,
            'status' => OrderStatus::Placed,
            'tracking_number' => $tracking,
            'subtotal_minor' => 250000,
        ]);

        OrderItem::factory()->create([
            'seller_order_id' => $sellerOrder->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price_minor' => 250000,
        ]);

        Payment::factory()->create([
            'seller_order_id' => $sellerOrder->id,
            'method' => PaymentMethod::Cod,
            'status' => PaymentStatus::Pending,
            'amount_minor' => 250000,
        ]);

        return [$seller, $sellerOrder->fresh(), $buyer, (string) $tracking];
    }

    public function test_service_encodes_only_the_tracking_reference(): void
    {
        $qr = new WaybillQrCode;
        $tracking = 'TRK-20261021-ABC123';

        $this->assertSame($tracking, $qr->payload($tracking));

        $svg = $qr->svg($tracking);

        $this->assertNotNull($svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringNotContainsString('Maria', $svg);
        $this->assertStringNotContainsString('09171234567', $svg);
        $this->assertStringNotContainsString('2500', $svg);
        $this->assertStringNotContainsString('token', strtolower($svg));
    }

    public function test_service_returns_null_when_tracking_is_missing(): void
    {
        $qr = new WaybillQrCode;

        $this->assertNull($qr->payload(null));
        $this->assertNull($qr->payload(''));
        $this->assertNull($qr->payload('   '));
        $this->assertNull($qr->svg(null));
        $this->assertNull($qr->svg(''));
    }

    public function test_service_output_is_stable_for_the_same_tracking_number(): void
    {
        $qr = new WaybillQrCode;
        $tracking = 'TRK-20261021-STABLE';

        $this->assertSame($qr->svg($tracking), $qr->svg($tracking));
    }

    public function test_owner_sees_qr_with_tracking_payload_on_waybill(): void
    {
        [$seller, $sellerOrder, , $tracking] = $this->shipmentWithTracking();

        $html = $this->actingAs($seller, 'seller')
            ->get(route('seller.shipments.label', $sellerOrder))
            ->assertOk()
            ->assertSee($tracking, false)
            ->assertSee('Print waybill', false)
            ->assertSee('data-qr-payload="'.$tracking.'"', false)
            ->assertSee('<svg', false)
            ->assertSee('window.print()', false)
            ->assertSee('COLLECT ON DELIVERY', false)
            ->getContent();

        // QR payload attribute is the opaque tracking string only.
        $this->assertMatchesRegularExpression(
            '/data-qr-payload="'.preg_quote($tracking, '/').'"/',
            $html,
        );

        // Extract the QR wrapper and ensure buyer PII / COD are not inside it.
        preg_match('/data-qr-payload="[^"]+"[^>]*>.*?<\/div>/s', $html, $qrBlock);
        $this->assertNotEmpty($qrBlock[0] ?? null);
        $this->assertStringNotContainsString('09171234567', $qrBlock[0]);
        $this->assertStringNotContainsString('Maria Santos', $qrBlock[0]);
        $this->assertStringNotContainsString('₱2,500.00', $qrBlock[0]);
    }

    public function test_printed_waybill_masks_buyer_name_and_phone(): void
    {
        [$seller, $sellerOrder] = $this->shipmentWithTracking();

        $html = $this->actingAs($seller, 'seller')
            ->get(route('seller.shipments.label', $sellerOrder))
            ->assertOk()
            ->assertSee('Cash on Delivery', false)
            ->assertSee('COLLECT ON DELIVERY', false)
            ->getContent();

        $this->assertStringNotContainsString('Maria Santos', $html);
        $this->assertStringNotContainsString('09171234567', $html);
        $this->assertStringNotContainsString('123 Secret Street', $html);
        $this->assertStringContainsString('Quezon City', $html);
        $this->assertStringContainsString('Holy Spirit', $html);
        $this->assertStringContainsString('M***a S****s', $html);
        $this->assertStringContainsString('0917 *** **67', $html);
    }

    public function test_waybill_preserves_print_styles_and_quiet_zone_canvas(): void
    {
        [$seller, $sellerOrder] = $this->shipmentWithTracking();

        $this->actingAs($seller, 'seller')
            ->get(route('seller.shipments.label', $sellerOrder))
            ->assertOk()
            ->assertSee('@media print', false)
            ->assertSee('print-color-adjust: exact', false)
            ->assertSee('width: 168px', false)
            ->assertSee('height: 168px', false);
    }

    public function test_missing_tracking_never_fakes_a_qr_identifier(): void
    {
        [$seller, $sellerOrder] = $this->shipmentWithTracking(null);

        $this->actingAs($seller, 'seller')
            ->get(route('seller.shipments.label', $sellerOrder))
            ->assertOk()
            ->assertSee('QR unavailable — tracking not assigned', false)
            ->assertDontSee('data-qr-payload=', false)
            ->assertSee('— PENDING —', false);
    }

    public function test_foreign_seller_cannot_print_another_shops_waybill(): void
    {
        [, $sellerOrder] = $this->shipmentWithTracking();
        $other = Seller::factory()->create(['status' => 'approved']);

        $this->actingAs($other, 'seller')
            ->get(route('seller.shipments.label', $sellerOrder))
            ->assertForbidden();
    }

    public function test_guest_cannot_print_waybill(): void
    {
        [, $sellerOrder] = $this->shipmentWithTracking();

        $this->get(route('seller.shipments.label', $sellerOrder))
            ->assertRedirect();
    }
}
