<?php

namespace App\Services\Seller;

use App\Enums\StoreSaleStatus;
use App\Models\Catalog\Product;
use App\Models\Seller\Seller;
use App\Models\Seller\StoreSale;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every write to a store sale. A product runs at most one sale at a time,
 * whichever variants those sales target. The product row is locked before the
 * overlap check, so two concurrent saves cannot both pass it.
 */
class StoreSaleService
{
    /** What may still change once a sale is running: its target and start are history. */
    private const EDITABLE_WHILE_ONGOING = ['type', 'value', 'flash_quantity', 'ends_at'];

    /** @param array<string, mixed> $attributes */
    public function create(Seller $seller, array $attributes): StoreSale
    {
        return DB::transaction(function () use ($seller, $attributes): StoreSale {
            $this->assertNoOverlap($attributes['product_id'], $attributes['starts_at'], $attributes['ends_at']);

            return StoreSale::create(['seller_id' => $seller->id] + $attributes);
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(StoreSale $sale, array $attributes): StoreSale
    {
        return DB::transaction(function () use ($sale, $attributes): StoreSale {
            $sale = StoreSale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();
            $status = $sale->status();

            if ($status === StoreSaleStatus::Ended) {
                throw ValidationException::withMessages(['sale' => 'This sale has ended and can no longer be edited.']);
            }

            if ($status === StoreSaleStatus::Ongoing) {
                $attributes = Arr::only($attributes, self::EDITABLE_WHILE_ONGOING);
            }

            $this->assertNoOverlap(
                $attributes['product_id'] ?? $sale->product_id,
                $attributes['starts_at'] ?? $sale->starts_at,
                $attributes['ends_at'],
                $sale->id,
            );

            $sale->update($attributes);

            return $sale;
        });
    }

    /** End a running sale now. Its time slot is free for a new sale straight away. */
    public function end(StoreSale $sale): StoreSale
    {
        return DB::transaction(function () use ($sale): StoreSale {
            $sale = StoreSale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($sale->status() !== StoreSaleStatus::Ongoing) {
                throw ValidationException::withMessages(['sale' => 'Only a running sale can be ended.']);
            }

            // Times are stored to the second, and a sale must end after it starts.
            $sale->update(['ends_at' => now()->startOfSecond()->max($sale->starts_at->copy()->addSecond())]);

            return $sale;
        });
    }

    public function delete(StoreSale $sale): void
    {
        DB::transaction(function () use ($sale): void {
            $sale = StoreSale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($sale->status() === StoreSaleStatus::Ongoing) {
                throw ValidationException::withMessages(['sale' => 'This sale is running. End it before deleting it.']);
            }

            $sale->delete();
        });
    }

    private function assertNoOverlap(int $productId, CarbonInterface $startsAt, CarbonInterface $endsAt, ?int $ignoreSaleId = null): void
    {
        Product::withTrashed()->whereKey($productId)->lockForUpdate()->first();

        // Touching end to start is fine: a sale is over at the moment it ends.
        $clash = StoreSale::query()
            ->where('product_id', $productId)
            ->when($ignoreSaleId, fn ($query) => $query->whereKeyNot($ignoreSaleId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->orderBy('starts_at')
            ->first();

        if ($clash !== null) {
            $format = fn (CarbonInterface $at) => $at->copy()->timezone(StoreSale::TIMEZONE)->format('M j, Y g:i A');

            throw ValidationException::withMessages([
                'sale' => sprintf('This product already has a sale from %s to %s. Sale times cannot overlap.', $format($clash->starts_at), $format($clash->ends_at)),
            ]);
        }
    }
}
