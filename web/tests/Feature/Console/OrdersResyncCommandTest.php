<?php

namespace Tests\Feature\Console;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Seller\Seller;
use App\Models\Shared\Order;
use App\Models\Shared\Payment;
use App\Models\Shared\SellerOrder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OrdersResyncCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Build a minimal two-slice order at the given statuses.
     */
    private function makeOrder(OrderStatus $statusA, OrderStatus $statusB): Order
    {
        $order = Order::factory()->create();

        $soA = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->getKey(),
            'status' => $statusA->value,
        ]);

        $soB = SellerOrder::factory()->create([
            'order_id' => $order->id,
            'seller_id' => Seller::factory()->create(['status' => 'approved'])->getKey(),
            'status' => $statusB->value,
        ]);

        Payment::factory()->create([
            'seller_order_id' => $soA->id,
            'amount_minor' => $soA->subtotal_minor,
        ]);

        Payment::factory()->create([
            'seller_order_id' => $soB->id,
            'amount_minor' => $soB->subtotal_minor,
        ]);

        return $order;
    }

    public function test_dry_run_does_not_change_anything(): void
    {
        // Stale: both slices Delivered but order still PLACED.
        $order = $this->makeOrder(OrderStatus::Delivered, OrderStatus::Delivered);

        $this->assertSame(OrderStatus::Placed, $order->status);

        $exitCode = Artisan::call('orders:resync', ['--dry-run' => true]);

        $this->assertSame(0, $exitCode);

        // Nothing written.
        $this->assertSame(OrderStatus::Placed, $order->fresh()->status);
        $this->assertSame(PaymentStatus::Pending, $order->fresh()->sellerOrders->first()->payment->status);
    }

    public function test_real_run_fixes_stale_order_and_settles_payments(): void
    {
        // Stale: both slices Delivered but order still PLACED; payments pending.
        $order = $this->makeOrder(OrderStatus::Delivered, OrderStatus::Delivered);

        $this->assertSame(OrderStatus::Placed, $order->status);
        $this->assertSame(PaymentStatus::Pending, $order->sellerOrders()->first()->payment()->first()->status);

        $exitCode = Artisan::call('orders:resync');

        $this->assertSame(0, $exitCode);

        // Order rolled up to Delivered (both slices Delivered).
        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);

        // Both COD payments settled.
        foreach ($order->fresh()->sellerOrders as $so) {
            $this->assertSame(PaymentStatus::Paid, $so->payment->status);
            $this->assertNotNull($so->payment->paid_at);
        }
    }

    public function test_resync_is_idempotent(): void
    {
        $order = $this->makeOrder(OrderStatus::Delivered, OrderStatus::Delivered);

        Artisan::call('orders:resync');

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);

        // Second run: nothing to change, no errors.
        Artisan::call('orders:resync');

        $this->assertSame(OrderStatus::Delivered, $order->fresh()->status);
    }

    public function test_resync_sets_parent_to_completed_when_all_slices_completed(): void
    {
        $order = $this->makeOrder(OrderStatus::Completed, OrderStatus::Completed);

        $this->assertSame(OrderStatus::Placed, $order->status);

        Artisan::call('orders:resync');

        $this->assertSame(OrderStatus::Completed, $order->fresh()->status);
    }
}
