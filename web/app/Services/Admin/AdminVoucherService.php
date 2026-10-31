<?php

namespace App\Services\Admin;

use App\Enums\VoucherScope;
use App\Models\Shared\Voucher;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin writes for marketplace-funded vouchers. Platform vouchers discount
 * merchandise and shipping vouchers discount shipping fees. Shop vouchers
 * stay with the Seller who funds them.
 */
class AdminVoucherService
{
    /** All that may change once a buyer has claimed or used the voucher, and only in the buyer's favour. */
    private const EDITABLE_ONCE_LOCKED = ['expires_at', 'usage_limit', 'per_buyer_limit'];

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $productIds
     */
    public function create(array $attributes, array $productIds): Voucher
    {
        $this->assertMarketplaceScope($attributes['scope'] ?? null);

        return $this->guardingCode(fn (): Voucher => DB::transaction(function () use ($attributes, $productIds): Voucher {
            $voucher = Voucher::create([
                'seller_id' => null,
                'funding_source' => Voucher::FUNDING_MARKETPLACE,
            ] + $attributes);
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
            $voucher = $this->lockedMarketplace($voucher);

            if ($voucher->hasEnded()) {
                throw ValidationException::withMessages(['voucher' => 'This voucher has ended and can no longer be edited.']);
            }

            $locked = $voucher->rulesLocked();

            if ($locked) {
                $attributes = Arr::only($attributes, self::EDITABLE_ONCE_LOCKED);
                $this->assertOnlyInBuyersFavour($voucher, $attributes);

                if ($voucher->expires_at?->copy()->startOfMinute()->eq($attributes['expires_at'])) {
                    unset($attributes['expires_at']);
                }
            } else {
                $this->assertMarketplaceScope($attributes['scope'] ?? $voucher->effectiveScope());
            }

            if (isset($attributes['usage_limit']) && $attributes['usage_limit'] < $voucher->times_used) {
                throw ValidationException::withMessages([
                    'usage_limit' => "This voucher has already been used {$voucher->times_used} times.",
                ]);
            }

            $voucher->update([
                'seller_id' => null,
                'funding_source' => Voucher::FUNDING_MARKETPLACE,
            ] + $attributes);

            if (! $locked) {
                $voucher->products()->sync($productIds);
            }

            return $voucher;
        }));
    }

    /** End the voucher for good and drop unused wallet entries. */
    public function end(Voucher $voucher): Voucher
    {
        return DB::transaction(function () use ($voucher): Voucher {
            $voucher = $this->lockedMarketplace($voucher);

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

    public function delete(Voucher $voucher): void
    {
        DB::transaction(function () use ($voucher): void {
            $voucher = $this->lockedMarketplace($voucher);

            if ($voucher->rulesLocked()) {
                throw ValidationException::withMessages([
                    'voucher' => 'Buyers have claimed or used this voucher, so it cannot be deleted. End it instead.',
                ]);
            }

            $voucher->delete();
        });
    }

    private function lockedMarketplace(Voucher $voucher): Voucher
    {
        $voucher = Voucher::query()->whereKey($voucher->id)->lockForUpdate()->firstOrFail();
        $this->assertMarketplaceScope($voucher->effectiveScope());

        return $voucher;
    }

    private function assertMarketplaceScope(mixed $scope): void
    {
        $scope = $scope instanceof VoucherScope ? $scope : VoucherScope::tryFrom((string) $scope);

        if (! in_array($scope, [VoucherScope::Platform, VoucherScope::Shipping], true)) {
            throw ValidationException::withMessages([
                'scope' => 'Choose a platform voucher or a shipping voucher.',
            ]);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function assertOnlyInBuyersFavour(Voucher $voucher, array $attributes): void
    {
        $errors = [];

        if ($voucher->expires_at !== null && $attributes['expires_at']->lt($voucher->expires_at->copy()->startOfMinute())) {
            $errors['expires_at'] = 'Buyers have claimed or used this voucher, so its end date can only be extended.';
        }

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

    private function guardingCode(callable $write): Voucher
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['code' => 'This voucher code is already taken.']);
        }
    }
}
