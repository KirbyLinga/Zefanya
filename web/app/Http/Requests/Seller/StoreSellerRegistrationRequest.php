<?php

namespace App\Http\Requests\Seller;

use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use App\Support\EmailRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreSellerRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $email = $this->input('email');
            if (is_string($email) && ! $validator->errors()->has('email') && EmailRegistry::otherRolesFor($email, 'seller') !== []) {
                $validator->errors()->add('email', 'This email is already registered under another account type.');
            }
        }];
    }

    public function rules(): array
    {
        $isManual = $this->input('address_mode') === 'manual';

        return [
            'last_name' => [
                'required',
                'string',
                'min:2',
                'max:100',
                'regex:/^[\p{L}\s\'-]+$/u', // Letters (including accented), spaces, hyphens, apostrophes only
            ],
            'first_name' => [
                'required',
                'string',
                'min:2',
                'max:100',
                'regex:/^[\p{L}\s\'-]+$/u', // Letters (including accented), spaces, hyphens, apostrophes only
            ],
            'middle_initial' => ['nullable', 'string', 'max:2', 'regex:/^[\p{L}\.]+$/u'],
            'sex' => ['required', Rule::in(['male', 'female'])],

            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                function ($attribute, $value, $fail) {
                    // Check if email exists as a seller (case-insensitive)
                    // Only enforce uniqueness for sellers with verified status (not pending_verification)
                    if (Seller::whereRaw('LOWER(email) = ?', [strtolower($value)])
                        ->whereIn('status', ['pending_approval', 'approved', 'rejected'])
                        ->exists()
                    ) {
                        $fail('This email is already registered. Use a different email.');
                    }
                    // Check if email exists as a buyer (case-insensitive)
                    if (Buyer::whereRaw('LOWER(email) = ?', [strtolower($value)])->exists()) {
                        $fail('This email is already registered as a buyer. Use a different email.');
                    }
                },
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:255',
                'confirmed',
                'regex:/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[a-zA-Z\d@$!%*?&]+$/', // Requires uppercase, lowercase, number, special char
            ],

            'contact_no' => [
                'required',
                'regex:/^09\d{9}$/',
                Rule::unique('sellers', 'contact_no')->where(function ($query) {
                    // Only enforce uniqueness for sellers with completed registration
                    $query->whereIn('status', ['pending_approval', 'approved', 'rejected']);
                }),
            ],
            'birthday' => ['required', 'date', 'before:today'],

            'address_mode' => ['required', Rule::in(['api', 'manual'])],

            'province' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'province_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'municipality' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'municipality_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'barangay' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],
            'barangay_name' => [Rule::requiredIf(! $isManual), 'nullable', 'string'],

            'street' => ['required', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'address_detail' => ['nullable', 'string', 'max:255'],

            'business_name' => ['required', 'string', 'max:255'],
            'line_of_business_id' => ['required', 'integer', 'exists:categories,id'],

            'upload_id' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'business_permit' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => 'First name is required.',
            'first_name.min' => 'First name must be at least 2 characters.',
            'first_name.regex' => 'First name must contain only letters, spaces, hyphens, and apostrophes.',
            'last_name.required' => 'Last name is required.',
            'last_name.min' => 'Last name must be at least 2 characters.',
            'last_name.regex' => 'Last name must contain only letters, spaces, hyphens, and apostrophes.',
            'middle_initial.regex' => 'Middle initial must contain only letters and periods.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'This email is already registered. Use a different email.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.regex' => 'Password must include uppercase, lowercase, number, and special character (@$!%*?&).',
            'password.confirmed' => 'Password confirmation does not match.',
            'contact_no.regex' => 'Enter a valid Philippine mobile number (09XXXXXXXXX).',
            'contact_no.unique' => 'This mobile number is already registered.',
            'upload_id.mimes' => 'Upload ID must be a JPG, PNG, or PDF.',
            'upload_id.max' => 'Upload ID must be smaller than 5MB.',
            'business_permit.mimes' => 'Business permit must be a JPG, PNG, or PDF.',
            'business_permit.max' => 'Business permit must be smaller than 5MB.',
            'street.required' => 'Street / house number is required.',
            'province.required' => 'Select a province, or switch to manual address entry.',
            'line_of_business_id.required' => 'Select your line of business.',
        ];
    }
}
