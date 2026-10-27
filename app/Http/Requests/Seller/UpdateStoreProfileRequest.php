<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStoreProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('seller')?->isApproved() === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('contact_no'))) {
            $phone = preg_replace('/[\s\-()]/u', '', trim($this->input('contact_no')));
            if (str_starts_with($phone, '+63')) {
                $phone = '0'.substr($phone, 3);
            }
            $this->merge(['contact_no' => $phone]);
        }
        if (is_string($this->input('contact_email'))) {
            $this->merge(['contact_email' => mb_strtolower(trim($this->input('contact_email')))]);
        }
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'store_description' => ['nullable', 'string', 'max:2000'],
            'contact_no' => ['required', 'string', 'regex:/^09\d{9}$/', Rule::unique('sellers', 'contact_no')->ignore($this->user('seller'))],
            'contact_email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'region_code' => ['required', 'string', 'max:20'],
            'province_code' => ['required', 'string', 'max:20'],
            'municipality_code' => ['required', 'string', 'max:20'],
            'barangay_code' => ['required', 'string', 'max:20'],
            'street' => ['required', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'address_detail' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'logo' => ['nullable', Rule::prohibitedIf(fn () => $this->boolean('remove_logo')), 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=2048,max_height=2048'],
            'remove_logo' => ['sometimes', 'boolean'],
        ];
    }
}
