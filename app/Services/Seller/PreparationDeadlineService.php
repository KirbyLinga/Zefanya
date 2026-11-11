<?php

namespace App\Services\Seller;

use App\Enums\OrderStatus;
use App\Models\Shared\SellerOrder;
use App\Notifications\Seller\PreparationOverdue;
use App\Support\RuntimeSchema;
use Illuminate\Support\Facades\DB;

/**
 * Preparation deadlines are a promise, not an automatic cancellation. An
 * accepted order that passes its deadline notifies its Seller exactly once so
 * the Seller can pack it, ask for help, or cancel before physical pickup.
 */
class PreparationDeadlineService
{
    /**
     * @return array{notified: int}
     */
    public function escalateOverdue(): array
    {
        if (! RuntimeSchema::hasColumn('seller_orders', 'preparation_due_at')
            || ! RuntimeSchema::hasColumn('seller_orders', 'preparation_overdue_notified_at')) {
            return ['notified' => 0];
        }

        $notified = 0;

        SellerOrder::query()
            ->whereIn('status', [OrderStatus::Confirmed->value, OrderStatus::Preparing->value])
            ->whereNotNull('preparation_due_at')
            ->where('preparation_due_at', '<', now())
            ->whereNull('preparation_overdue_notified_at')
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$notified): void {
                foreach ($orders as $order) {
                    $claimed = DB::transaction(function () use ($order): bool {
                        $locked = SellerOrder::query()->whereKey($order->getKey())->lockForUpdate()->first();
                        if ($locked === null
                            || $locked->preparation_overdue_notified_at !== null
                            || ! $locked->preparationOverdue()) {
                            return false;
                        }

                        $locked->forceFill(['preparation_overdue_notified_at' => now()])->save();

                        return true;
                    });

                    if (! $claimed) {
                        continue;
                    }

                    $notified++;
                    $order->loadMissing(['order', 'seller']);
                    try {
                        $order->seller?->notify(new PreparationOverdue($order->fresh()));
                    } catch (\Throwable $exception) {
                        // The escalation stamp is already saved; a mail failure
                        // must not make the command retry and spam the Seller.
                        report($exception);
                    }
                }
            });

        return ['notified' => $notified];
    }
}
