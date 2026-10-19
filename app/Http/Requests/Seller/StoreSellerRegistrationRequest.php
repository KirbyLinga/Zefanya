<?php

namespace App\Http\Requests\Seller;

use App\Models\Seller\Seller;
use App\Support\EmailRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSellerRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $email = $this->input('email');
        if ($email) {
            $this->merge(['email' => trim(strtolower($email))]);
        }

        $contactNo = $this->input('contact_no');
        if ($contactNo) {
            $phone = preg_replace('/[\s\-()]/u', '', (string) $contactNo);
            if (str_starts_with($phone, '+63')) {
                $phone = '0'.substr($phone, 3);
            }
            $this->merge(['contact_no' => $phone]);
        }
    }

    public function rules(): array
    {
        return [
            'last_name' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[\p{L}\s\'-]+$/u', // Letters (incl. accented), spaces, hyphens, apostrophes
            ],
            'first_name' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[\p{L}\s\'-]+$/u', // Letters (incl. accented), spaces, hyphens, apostrophes
            ],
            'middle_initial' => ['nullable', 'string', 'max:2', 'regex:/^[\p{L}\.]+$/u'],
            'sex' => ['required', Rule::in(['male', 'female'])],

            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                function ($attribute, $value, $fail) {
                    // Check if email exists as a seller (case-insensitive).
                    // Unverified and REJECTED rows are ignored so a failed OTP
                    // email or a rejection can be retried with the same email.
                    if (Seller::whereRaw('LOWER(email) = ?', [strtolower($value)])
                        ->whereIn('status', ['pending', 'approved'])
                        ->exists()
                    ) {
                        $fail('This email is already registered. Use a different email.');

                        return;
                    }

                    // One email = one role across every account table
                    // (buyers, sellers, logistics providers, admins).
                    $foreign = EmailRegistry::otherRolesFor($value, 'seller');

                    if ($foreign !== []) {
                        $fail('This email is already registered as a '.EmailRegistry::labelList($foreign).'. Use a different email.');

                        return;
                    }

                    // Never let a newcomer replace a registration that is still
                    // inside its 10-minute verification window.
                    $stale = Seller::whereRaw('LOWER(email) = ?', [strtolower($value)])
                        ->where('status', 'unverified')
                        ->first();

                    if ($stale && ! $stale->isAbandonedRegistration()) {
                        $fail('This email is currently awaiting verification. Please finish verifying it, then try again later.');
                    }
                },
            ],
            'password' => [
                'required',
                'string',
                'min:8',
                'max:255',
                'confirmed',
                // Same complexity rule as the registration modal: uppercase,
                // lowercase, number and special character (mirrors registration-validation.js).
                'regex:/^(?=.*\p{Lu})(?=.*\p{Ll})(?=.*\p{N})(?=.*[\p{Z}\p{S}\p{P}]).+$/u',
            ],

            'contact_no' => [
                'required',
                'regex:/^09\d{9}$/',
                // Live numbers unique; same-email unverified/rejected is archived on store.
                Rule::unique('sellers', 'contact_no')
                    ->whereNull('archived_at')
                    ->where(function ($query) {
                        $email = strtolower(trim((string) $this->input('email')));
                        $query->whereRaw(
                            '(LOWER(email) != ? OR status NOT IN (?, ?))',
                            [$email, 'unverified', 'rejected']
                        );
                    }),
            ],
            'birthday' => ['required', 'date', 'before:today'],

            // Registration: province / municipality / barangay from dropdowns only.
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
            'first_name.regex' => 'First name can only contain letters, spaces, hyphens and apostrophes.',
            'last_name.required' => 'Last name is required.',
            'last_name.min' => 'Last name must be at least 2 characters.',
            'last_name.regex' => 'Last name can only contain letters, spaces, hyphens and apostrophes.',
            'middle_initial.regex' => 'Middle initial must contain only letters and periods.',
            'email.email' => 'Enter a valid email address.',
            'email.unique' => 'This email is already registered. Use a different email.',
            'password.min' => 'Password must be at least 8 characters.',
            'password.regex' => 'Password must include uppercase, lowercase, number, and special character (@$!%*?&).',
            'password.confirmed' => 'Password confirmation does not match.',
            'contact_no.regex' => 'Enter a valid Philippine mobile number (09XXXXXXXXX).',
            'contact_no.unique' => 'This mobile number is already registered.',
            'upload_id.uploaded' => 'Upload ID failed to upload. Use a JPG, PNG, or PDF under 5MB.',
            'upload_id.mimes' => 'Upload ID must be a JPG, PNG, or PDF.',
            'upload_id.max' => 'Upload ID must be smaller than 5MB.',
            'business_permit.uploaded' => 'Business permit failed to upload. Use a JPG, PNG, or PDF under 5MB.',
            'business_permit.mimes' => 'Business permit must be a JPG, PNG, or PDF.',
            'business_permit.max' => 'Business permit must be smaller than 5MB.',
            'street.required' => 'Street / house number is required.',
            'province.required' => 'Select a province.',
            'line_of_business_id.required' => 'Select your line of business.',
        ];
    }
}
