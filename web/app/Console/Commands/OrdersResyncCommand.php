<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Backfill command: re-derive orders.status from seller_orders and settle
 * pending COD payments for Delivered/Completed seller_orders.
 *
 * Idempotent and safe to re-run. Use --dry-run first to preview changes.
 */
class OrdersResyncCommand extends Command
{
    protected $signature = 'orders:resync {--dry-run : Print what would change without writing}';

    protected $description = 'Roll up orders.status from seller_orders and settle pending COD payments';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $orderChanges = 0;
        $paymentChanges = 0;

        Order::query()
            ->with('sellerOrders.payment')
            ->chunkById(100, function (Collection $orders) use ($dryRun, &$orderChanges, &$paymentChanges): void {
                foreach ($orders as $order) {
                    $newStatus = $order->computeRollupStatus();

                    if ($newStatus !== null && $order->status !== $newStatus) {
                        $orderChanges++;

                        if ($dryRun) {
                            $this->line("[DRY-RUN] Order #{$order->id}: {$order->status->value} → {$newStatus->value}");
                        } else {
                            $order->forceFill(['status' => $newStatus])->save();
                        }
                    }

                    foreach ($order->sellerOrders as $sellerOrder) {
                        if (! in_array($sellerOrder->status, [OrderStatus::Delivered, OrderStatus::Completed], true)) {
                            continue;
                        }

                        $payment = $sellerOrder->payment;

                        if ($payment === null
                            || $payment->method !== PaymentMethod::Cod
                            || $payment->status !== PaymentStatus::Pending
                        ) {
                            continue;
                        }

                        $paymentChanges++;

                        if ($dryRun) {
                            $this->line("[DRY-RUN] Payment #{$payment->id}: pending → paid (seller_order #{$sellerOrder->id}, COD)");
                        } else {
                            $payment->markPaid();
                        }
                    }
                }

                unset($orders);
            });

        if ($dryRun) {
            $this->info("[DRY-RUN] {$orderChanges} order(s) would have status updated, {$paymentChanges} payment(s) would be settled.");
        } else {
            $this->info("{$orderChanges} order(s) updated, {$paymentChanges} payment(s) settled.");
        }

        return self::SUCCESS;
    }
}
