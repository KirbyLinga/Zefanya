<?php

namespace App\Http\Requests\Seller;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class UpdateStoreProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $seller = $this->user('seller');
        if ($seller?->isApproved() !== true) {
            return false;
        }
        foreach (['id', 'seller_id', 'store_id'] as $key) {
            if ($this->has($key) && (! is_scalar($this->input($key)) || (string) $this->input($key) !== (string) $seller->id)) {
                return false;
            }
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('business_name'))) {
            $this->merge(['business_name' => trim($this->input('business_name'))]);
        }
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
        $validImage = function (string $attribute, mixed $file, \Closure $fail): void {
            if (! $file instanceof UploadedFile || @getimagesize($file->getPathname()) === false) {
                $fail('Upload a valid JPG/PNG image, at most 5 MB.');
            }
        };

        return [
            'business_name' => ['required', 'string', 'min:3', 'max:30'],
            'store_description' => ['nullable', 'string', 'max:500'],
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
            'logo' => ['nullable', Rule::prohibitedIf(fn () => $this->boolean('remove_logo')), 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120', $validImage],
            'banner' => ['nullable', Rule::prohibitedIf(fn () => $this->boolean('remove_banner')), 'file', 'image', 'mimes:jpg,jpeg,png', 'max:5120', $validImage],
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_banner' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_name.min' => 'The store name must contain 3–30 characters.',
            'business_name.max' => 'The store name must contain 3–30 characters.',
            'store_description.max' => 'The store description may contain at most 500 characters.',
            'logo.*' => 'The logo must be a JPG/PNG image, at most 5 MB. Remove it or upload a replacement, not both.',
            'banner.*' => 'The banner must be a JPG/PNG image, at most 5 MB. Remove it or upload a replacement, not both.',
        ];
    }
}
