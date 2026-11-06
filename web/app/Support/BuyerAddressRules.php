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
    /** @return array<string, array<int, mixed>> */
    public static function rules(?string $addressMode): array
    {
        $isManual = $addressMode === 'manual';

        return [
            'address_mode' => ['required', Rule::in(['api', 'manual'])],
            'province' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'province_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'municipality' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            // Required in BOTH modes — see StoreBuyerRegistrationRequest: a
            // missing municipality breaks service-area matching at fulfillment.
            'municipality_name' => ['required', 'string'],
            'barangay' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'barangay_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'street' => ['required', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'address_detail' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
        ];
    }
}
