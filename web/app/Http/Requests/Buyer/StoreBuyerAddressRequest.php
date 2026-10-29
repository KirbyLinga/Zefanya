<?php

namespace App\Http\Requests\Buyer;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBuyerAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof \App\Models\Buyer\Buyer;
    }

    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'regex:/^(09\d{9}|\+639\d{9})$/'],
            'label' => ['nullable', 'string', Rule::in(['Home', 'Work', 'Other'])],
            'region' => ['required', 'string', 'max:100'],
            'region_code' => ['nullable', 'string', 'max:10'],
            'province' => ['nullable', 'string', 'max:100'],
            'province_code' => ['nullable', 'string', 'max:10'],
            'city' => ['required', 'string', 'max:100'],
            'city_code' => ['nullable', 'string', 'max:10'],
            'barangay' => ['required', 'string', 'max:100'],
            'barangay_code' => ['nullable', 'string', 'max:10'],
            'street' => ['required', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'latitude' => ['nullable', 'numeric'],
            'longitude' => ['nullable', 'numeric'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }
}
