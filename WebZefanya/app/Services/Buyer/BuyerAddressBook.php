<?php

namespace App\Services\Buyer;

use App\Exceptions\CheckoutException;
use App\Models\Buyer\Buyer;
use App\Models\Buyer\BuyerAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BuyerAddressBook
{
    public const MAX = 10;

    public const LIMIT_MESSAGE = 'You can save at most 10 addresses.';

    public const DEFAULT_DELETE_MESSAGE = 'Choose another default address before deleting this one.';

    public const UNAVAILABLE_MESSAGE = 'That delivery address is no longer in your address book. Please choose another one.';

    /**
     * The address checkout delivers to. No id means the default; an id the buyer
     * does not own is refused. Null only for a buyer with no saved rows yet, who
     * still has the address captured at registration on their profile.
     *
     * @throws CheckoutException
     */
    public function chosen(Buyer $buyer, mixed $id): ?BuyerAddress
    {
        if (blank($id)) {
            return $buyer->addresses()->orderByDesc('is_default')->orderBy('id')->first();
        }

        $valid = is_int($id) || (is_string($id) && ctype_digit($id));

        return ($valid ? $buyer->addresses()->whereKey($id)->first() : null)
            ?? throw new CheckoutException(self::UNAVAILABLE_MESSAGE);
    }

    /**
     * An unsaved copy of the buyer carrying this address, for carrier quotes and
     * the order snapshot. Choosing an address must not change the profile.
     */
    public function deliveringTo(Buyer $buyer, ?BuyerAddress $address): Buyer
    {
        if ($address === null) {
            return $buyer;
        }

        return (clone $buyer)->forceFill($address->only([
            'address_mode',
            'province_code',
            'province_name',
            'municipality_code',
            'municipality_name',
            'barangay_code',
            'barangay_name',
            'street',
            'house_number',
            'address_detail',
            'postal_code',
        ]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Buyer $buyer, array $data, ?BuyerAddress $existing = null, bool $asDefault = false): BuyerAddress
    {
        return DB::transaction(function () use ($buyer, $data, $existing, $asDefault): BuyerAddress {
            $locked = Buyer::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();
            $count = BuyerAddress::query()->where('buyer_id', $locked->id)->count();

            if ($existing === null && $count >= self::MAX) {
                throw ValidationException::withMessages([
                    'address' => self::LIMIT_MESSAGE,
                ]);
            }

            if ($existing !== null && (int) $existing->buyer_id !== (int) $locked->id) {
                abort(404);
            }

            $asDefault = $asDefault || $count === 0 || (bool) $existing?->is_default;

            if ($asDefault) {
                // Leave $existing out: it still holds its old values in memory, so
                // the update below would see no change and not restore its flag.
                BuyerAddress::query()->where('buyer_id', $locked->id)
                    ->when($existing, fn ($query) => $query->whereKeyNot($existing->id))
                    ->update([
                        'is_default' => false,
                        'default_marker' => null,
                    ]);
            }

            $attributes = $this->attributes($data) + [
                'is_default' => $asDefault,
                'default_marker' => $asDefault ? 1 : null,
            ];

            if ($existing === null) {
                $address = $locked->addresses()->create($attributes);
            } else {
                $existing->update($attributes);
                $address = $existing;
            }

            $this->syncBuyer($locked->fresh());

            return $address->fresh();
        });
    }

    /** @param  array<string, mixed>  $data */
    public function saveDefault(Buyer $buyer, array $data): BuyerAddress
    {
        $default = $buyer->addresses()->where('is_default', true)->first();

        return $this->save($buyer, $data, $default, true);
    }

    public function makeDefault(Buyer $buyer, BuyerAddress $address): BuyerAddress
    {
        return $this->save($buyer, $this->inputFrom($address), $address, true);
    }

    public function delete(Buyer $buyer, BuyerAddress $address): void
    {
        DB::transaction(function () use ($buyer, $address): void {
            Buyer::query()->whereKey($buyer->id)->lockForUpdate()->firstOrFail();
            $address = BuyerAddress::query()->whereKey($address->id)->lockForUpdate()->firstOrFail();

            if ((int) $address->buyer_id !== (int) $buyer->id) {
                abort(404);
            }

            if ($address->is_default) {
                throw ValidationException::withMessages([
                    'address' => self::DEFAULT_DELETE_MESSAGE,
                ]);
            }

            $address->delete();
        });
    }

    /** @param  array<string, mixed>  $data */
    private function attributes(array $data): array
    {
        return [
            'address_mode' => $data['address_mode'],
            'province_code' => $data['province'] ?? null,
            'province_name' => $data['province_name'] ?? null,
            'municipality_code' => $data['municipality'] ?? null,
            'municipality_name' => $data['municipality_name'] ?? null,
            'barangay_code' => $data['barangay'] ?? null,
            'barangay_name' => $data['barangay_name'] ?? null,
            'street' => $data['street'],
            'house_number' => $data['house_number'] ?? null,
            'address_detail' => $data['address_detail'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function inputFrom(BuyerAddress $address): array
    {
        return [
            'address_mode' => $address->address_mode,
            'province' => $address->province_code,
            'province_name' => $address->province_name,
            'municipality' => $address->municipality_code,
            'municipality_name' => $address->municipality_name,
            'barangay' => $address->barangay_code,
            'barangay_name' => $address->barangay_name,
            'street' => $address->street,
            'house_number' => $address->house_number,
            'address_detail' => $address->address_detail,
            'postal_code' => $address->postal_code,
        ];
    }

    private function syncBuyer(Buyer $buyer): void
    {
        $default = $buyer->addresses()->where('is_default', true)->first();

        if ($default === null) {
            return;
        }

        $buyer->update([
            'address_mode' => $default->address_mode,
            'province_code' => $default->province_code,
            'province_name' => $default->province_name,
            'municipality_code' => $default->municipality_code,
            'municipality_name' => $default->municipality_name,
            'barangay_code' => $default->barangay_code,
            'barangay_name' => $default->barangay_name,
            'street' => $default->street,
            'house_number' => $default->house_number,
            'address_detail' => $default->address_detail,
            'postal_code' => $default->postal_code,
        ]);
    }
}
