<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBuyerAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof \App\Models\Buyer\Buyer;
    }

    public function rules(): array
    {
        return [
            'recipient' => ['sometimes', 'required', 'string', 'max:100'],
            'phone' => ['sometimes', 'required', 'string', 'regex:/^(09\d{9}|\+639\d{9})$/'],
            'label' => ['nullable', 'string', Rule::in(['Home', 'Work', 'Other'])],
            'region' => ['sometimes', 'required', 'string', 'max:100'],
            'region_code' => ['nullable', 'string', 'max:10'],
            'province' => ['nullable', 'string', 'max:100'],
            'province_code' => ['nullable', 'string', 'max:10'],
            'city' => ['sometimes', 'required', 'string', 'max:100'],
            'city_code' => ['nullable', 'string', 'max:10'],
            'barangay' => ['sometimes', 'required', 'string', 'max:100'],
            'barangay_code' => ['nullable', 'string', 'max:10'],
            'street' => ['sometimes', 'required', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
