<?php

namespace App\Services\Seller;

use App\Enums\VoucherScope;
use App\Models\Seller\Seller;
use App\Models\Shared\Voucher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every Seller write to a voucher. Seller vouchers are always shop vouchers
 * funded by that Seller: settlement stops on a voucher with no recorded funder.
 */
class SellerVoucherService
{
    /** All that may change once a buyer has claimed or used the voucher, and only in the buyer's favour. */
    private const EDITABLE_ONCE_LOCKED = ['expires_at', 'usage_limit', 'per_buyer_limit'];

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $productIds
     */
    public function create(Seller $seller, array $attributes, array $productIds): Voucher
    {
        return $this->guardingCode(fn (): Voucher => DB::transaction(function () use ($seller, $attributes, $productIds): Voucher {
            $voucher = Voucher::create($attributes + [
                'scope' => VoucherScope::Shop,
                'seller_id' => $seller->id,
                'funding_source' => Voucher::FUNDING_SELLER,
            ]);
            $voucher->products()->sync($productIds);

            return $voucher;
        }));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $productIds
     */
    public function update(Voucher $voucher, array $attributes, array $productIds): Voucher
    {
        return $this->guardingCode(fn (): Voucher => DB::transaction(function () use ($voucher, $attributes, $productIds): Voucher {
            $voucher = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($voucher->hasEnded()) {
                throw ValidationException::withMessages(['voucher' => 'This voucher has ended and can no longer be edited.']);
            }

            $locked = $voucher->rulesLocked();

            if ($locked) {
                $attributes = Arr::only($attributes, self::EDITABLE_ONCE_LOCKED);
                $this->assertOnlyInBuyersFavour($voucher, $attributes);

                // The form picks whole minutes; an untouched end time keeps its exact value.
                if ($voucher->expires_at?->copy()->startOfMinute()->eq($attributes['expires_at'])) {
                    unset($attributes['expires_at']);
                }
            }

            if (isset($attributes['usage_limit']) && $attributes['usage_limit'] < $voucher->times_used) {
                throw ValidationException::withMessages([
                    'usage_limit' => "This voucher has already been used {$voucher->times_used} times.",
                ]);
            }

            $voucher->update($attributes);

            if (! $locked) {
                $voucher->products()->sync($productIds);
            }

            return $voucher;
        }));
    }

    /**
     * End the voucher for good: nobody can use it from now on, and it leaves
     * every wallet where it was never used. Wallets holding an order keep their
     * entry as a record of that order.
     */
    public function end(Voucher $voucher): Voucher
    {
        return DB::transaction(function () use ($voucher): Voucher {
            $voucher = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($voucher->hasEnded()) {
                throw ValidationException::withMessages(['voucher' => 'This voucher has already ended.']);
            }

            $now = now()->startOfSecond();

            $voucher->update([
                'ended_at' => $now,
                'is_active' => false,
                'expires_at' => $voucher->expires_at === null || $voucher->expires_at->gt($now) ? $now : $voucher->expires_at,
            ]);

            $voucher->claims()->whereNotIn('buyer_id', $voucher->buyerIdsWithOrders())->delete();

            return $voucher;
        });
    }

    /** Orders keep a foreign key to the voucher they used, and wallets hold claims to honour. */
    public function delete(Voucher $voucher): void
    {
        DB::transaction(function () use ($voucher): void {
            $voucher = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();

            if ($voucher->rulesLocked()) {
                throw ValidationException::withMessages([
                    'voucher' => 'Buyers have claimed or used this voucher, so it cannot be deleted. End it instead.',
                ]);
            }

            $voucher->delete();
        });
    }

    /** @param array<string, mixed> $attributes */
    private function assertOnlyInBuyersFavour(Voucher $voucher, array $attributes): void
    {
        $errors = [];

        if ($voucher->expires_at !== null && $attributes['expires_at']->lt($voucher->expires_at->copy()->startOfMinute())) {
            $errors['expires_at'] = 'Buyers have claimed or used this voucher, so its end date can only be extended.';
        }

        // Null means no limit, which is the highest limit there is.
        foreach (['usage_limit' => 'total usage limit', 'per_buyer_limit' => 'per-buyer limit'] as $column => $label) {
            $current = $voucher->{$column};
            $next = $attributes[$column];

            if ($next !== null && ($current === null || $next < $current)) {
                $errors[$column] = "Buyers have claimed or used this voucher, so its {$label} can only be raised.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** The form checks uniqueness first; the database index settles a race between two sellers. */
    private function guardingCode(callable $write): Voucher
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'This voucher code is already taken.']);
        }
    }
}
