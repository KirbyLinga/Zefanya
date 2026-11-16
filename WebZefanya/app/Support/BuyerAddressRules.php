<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Validation rules for a buyer address-book entry, shared by the buyer web
 * account page and the Buyer mobile API (moved verbatim from
 * Buyer\AccountController::addressRules).
 */
final class BuyerAddressRules
{
    /**
     * Every saved address must be picked from the official PSGC lists, so it
     * always carries a barangay code and always passes BuyerAddressVerifier.
     * Free-text ("manual") entries are no longer accepted for new or edited
     * addresses; addresses already saved are not changed.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(?string $addressMode = null): array
    {
        return [
            'address_mode' => ['required', Rule::in(['api'])],
            'province' => ['required', 'string'],
            'province_name' => ['required', 'string'],
            'municipality' => ['required', 'string'],
            'municipality_name' => ['required', 'string'],
            'barangay' => ['required', 'string'],
            'barangay_name' => ['required', 'string'],
            'street' => ['required', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'address_detail' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'address_mode.in' => 'Choose your province, city or municipality and barangay from the lists. Typed-in addresses cannot be verified.',
            'province.required' => 'Choose your province from the list.',
            'municipality.required' => 'Choose your city or municipality from the list.',
            'barangay.required' => 'Choose your barangay from the list.',
        ];
    }
}
