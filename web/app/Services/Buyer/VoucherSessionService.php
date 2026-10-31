<?php

namespace App\Services\Buyer;

use App\Enums\VoucherScope;
use App\Models\Seller\Seller;
use App\Models\Shared\Voucher;
use Illuminate\Support\Collection;

/**
 * Owns the buyer's voucher slots in the session and re-validates them against
 * the current cart every time they are used.
 *
 * The session stores slot => code so applying another voucher to the same slot
 * replaces the previous one. Legacy single-code sessions (`cart_voucher`) are
 * read and migrated without breaking existing carts.
 */
final class VoucherSessionService
{
    public const SESSION_KEY = 'cart_vouchers';

    public const LEGACY_KEY = 'cart_voucher';

    /**
     * Resolve, validate, and stack every voucher currently stored for the cart.
     *
     * @param  Collection<int, array{seller: Seller, items: Collection}>  $sellerGroups
     * @param  array<int, int>  $shippingFees  Seller id => quoted fee
     * @param  bool  $lock  Lock voucher rows (checkout transaction)
     */
    public function resolve(
        Collection $sellerGroups,
        int $subtotalCentavos,
        array $shippingFees = [],
        ?string &$notice = null,
        bool $lock = false,
    ): VoucherApplication {
        $state = $this->state();

        if ($state === []) {
            $notice = null;

            return VoucherApplication::empty($sellerGroups, $shippingFees);
        }

        $codes = collect($state)
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $query = Voucher::query()->whereIn('code', $codes);
        if ($lock) {
            $query->orderBy('id')->lockForUpdate();
        }
        $found = $query->get()->keyBy(fn (Voucher $voucher): string => strtoupper($voucher->code));

        $notices = [];
        $applied = [];
        $dirty = false;

        foreach ($state as $storedSlot => $code) {
            $voucher = $found->get(strtoupper(trim((string) $code)));

            if ($voucher === null) {
                $notices[] = 'This voucher code is no longer valid.';
                $dirty = true;

                continue;
            }

            $reason = $this->rejectionReason($voucher, $sellerGroups, $subtotalCentavos, is_string($storedSlot) ? $storedSlot : null);
            if ($reason !== null) {
                $notices[] = $reason;
                $dirty = true;

                continue;
            }

            $slot = $voucher->slotKey();

            // A stale key (for example a legacy or re-scoped voucher) is
            // rewritten to the voucher's real slot rather than duplicated.
            if (is_string($storedSlot) && ! str_starts_with($storedSlot, '__') && $storedSlot !== $slot) {
                $dirty = true;
            }

            if (isset($applied[$slot]) && (int) $applied[$slot]->getKey() !== (int) $voucher->getKey()) {
                $dirty = true;
            }

            $applied[$slot] = $voucher;
        }

        // Never write session state from inside the checkout transaction.
        if ($dirty && ! $lock) {
            $this->writeState(collect($applied)->mapWithKeys(
                fn (Voucher $voucher): array => [$voucher->slotKey() => $voucher->code],
            )->all());
        }

        $notice = $notices === [] ? null : implode(' ', array_values(array_unique($notices)));

        return new VoucherApplication(collect(array_values($applied)), $sellerGroups, $shippingFees);
    }

    /**
     * Apply a voucher to its slot, replacing any voucher previously held there.
     */
    public function apply(
        Voucher $voucher,
        Collection $sellerGroups,
        int $subtotalCentavos,
        ?string &$notice = null,
        ?string $requestedSlot = null,
    ): bool {
        if ($requestedSlot !== null && $requestedSlot !== $voucher->slotKey()) {
            $notice = 'That voucher cannot be used in the selected voucher slot.';

            return false;
        }

        $reason = $this->rejectionReason($voucher, $sellerGroups, $subtotalCentavos, null);
        if ($reason !== null) {
            $notice = $reason;

            return false;
        }

        $kept = [];
        foreach ($this->modelsForState($this->state()) as $current) {
            if ((int) $current->getKey() === (int) $voucher->getKey()
                || $current->slotKey() === $voucher->slotKey()) {
                continue;
            }

            $kept[$current->slotKey()] = $current->code;
        }

        $kept[$voucher->slotKey()] = $voucher->code;
        $this->writeState($kept);

        $notice = null;

        return true;
    }

    /**
     * Remove one slot, or every applied voucher when no slot is given.
     */
    public function remove(?string $slot = null): void
    {
        if ($slot === null) {
            $this->clear();

            return;
        }

        $kept = [];
        foreach ($this->modelsForState($this->state()) as $current) {
            if ($current->slotKey() === $slot) {
                continue;
            }

            $kept[$current->slotKey()] = $current->code;
        }

        $this->writeState($kept);
    }

    public function clear(): void
    {
        session()->forget([self::SESSION_KEY, self::LEGACY_KEY]);
    }

    /**
     * Rejection reason for the voucher in the current cart context. The
     * existing Voucher::rejectionReason() remains the single source of truth
     * for active/expiry/usage/minimum checks.
     *
     * @param  Collection<int, array{seller: Seller, items: Collection}>  $sellerGroups
     */
    public function rejectionReason(
        Voucher $voucher,
        Collection $sellerGroups,
        int $subtotalCentavos,
        ?string $storedSlot = null,
    ): ?string {
        $scope = $voucher->effectiveScope();

        if (is_string($storedSlot) && ! str_starts_with($storedSlot, '__')
            && ! in_array($storedSlot, ['platform', 'shipping'], true)
            && ! str_starts_with($storedSlot, 'shop:')) {
            return 'The stored voucher slot is not valid. Please apply the code again.';
        }

        if ($scope === VoucherScope::Shop) {
            $group = $sellerGroups->first(
                fn (array $group): bool => (int) $group['seller']->getKey() === (int) $voucher->seller_id,
            );

            if ($group === null) {
                return 'This shop voucher is not available for the selected stores.';
            }

            $eligibleSubtotal = $voucher->eligibleSubtotal($group['items']);
        } else {
            $eligibleSubtotal = $subtotalCentavos;
        }

        if ($eligibleSubtotal <= 0) {
            return 'Add eligible items to your cart before using this voucher.';
        }

        $reason = $voucher->rejectionReason($eligibleSubtotal);
        if ($reason !== null) {
            return $reason;
        }

        // Checkout re-runs this with the buyer and voucher rows locked, so two
        // tabs cannot both pass the limit.
        $buyerId = auth('buyer')->id();
        if ($voucher->per_buyer_limit !== null && $buyerId !== null
            && $voucher->usesByBuyer((int) $buyerId) >= $voucher->per_buyer_limit) {
            return 'You have already used this voucher the maximum number of times.';
        }

        return null;
    }

    /**
     * Current session state, normalized to a list or slot => code map.
     *
     * @return array<int|string, string>
     */
    private function state(): array
    {
        $raw = session(self::SESSION_KEY, []);
        $state = [];

        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }

                $state[is_int($key) ? $key : (string) $key] = strtoupper(trim($value));
            }
        }

        $legacy = session(self::LEGACY_KEY);
        if (is_string($legacy) && trim($legacy) !== '') {
            $legacy = strtoupper(trim($legacy));
            if (! in_array($legacy, array_map(fn ($code): string => strtoupper((string) $code), $state), true)) {
                // The explicit slots take precedence over an older legacy key.
                $state = ['__legacy' => $legacy, ...$state];
            }
        }

        return $state;
    }

    /**
     * Fetch the voucher models represented by a state map without validating
     * them, so unrelated applied vouchers survive replacement.
     *
     * @param  array<int|string, string>  $state
     * @return Collection<int, Voucher>
     */
    private function modelsForState(array $state): Collection
    {
        $codes = collect($state)
            ->map(fn ($code): string => strtoupper(trim((string) $code)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($codes === []) {
            return collect();
        }

        return Voucher::query()->whereIn('code', $codes)->get();
    }

    /**
     * Persist slot => code, keeping the legacy platform key in sync for older
     * pages and tests.
     *
     * @param  array<string, string>  $state
     */
    private function writeState(array $state): void
    {
        if ($state === []) {
            $this->clear();

            return;
        }

        session([self::SESSION_KEY => $state]);

        $platformCode = $state['platform'] ?? null;
        if ($platformCode !== null) {
            session([self::LEGACY_KEY => $platformCode]);
        } else {
            session()->forget(self::LEGACY_KEY);
        }
    }
}
