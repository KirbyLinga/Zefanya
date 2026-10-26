<?php

namespace App\Http\Requests\Logistics;

use App\Enums\LogisticsProviderStatus;
use App\Models\Logistics\LogisticsProvider;
use App\Support\EmailRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLogisticsRegistrationRequest extends FormRequest
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
            // Names accept letters (incl. accented), spaces, hyphens and
            // apostrophes — same rule as buyer/seller registration.
            'last_name' => ['required', 'string', 'regex:/^[\p{L}\s\'-]+$/u', 'max:100'],
            'first_name' => ['required', 'string', 'regex:/^[\p{L}\s\'-]+$/u', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:2'],
            'sex' => ['required', Rule::in(['male', 'female'])],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                function ($attribute, $value, $fail) {
                    // Block only a LIVE logistics registration. A rejected (or
                    // abandoned unverified) provider may retry with the same
                    // email — a rejection must not be a permanent dead end.
                    $live = LogisticsProvider::whereRaw('LOWER(email) = ?', [strtolower($value)])
                        ->whereNotIn('status', ['unverified', 'rejected'])
                        ->exists();

                    if ($live) {
                        $fail('This email is already registered. Use a different email.');

                        return;
                    }

                    // One email = one role across every account table
                    // (buyers, sellers, logistics providers, admins).
                    $foreign = EmailRegistry::otherRolesFor($value, 'logistics');

                    if ($foreign !== []) {
                        $fail('This email is already registered as a '.EmailRegistry::labelList($foreign).'. Use a different email.');

                        return;
                    }

                    // Never let a newcomer replace a registration that is still
                    // inside its 10-minute verification window.
                    $stale = LogisticsProvider::whereRaw('LOWER(email) = ?', [strtolower($value)])
                        ->where('status', LogisticsProviderStatus::Unverified->value)
                        ->first();

                    if ($stale && ! $stale->isAbandonedRegistration()) {
                        $fail('This email is currently awaiting verification. Please finish verifying it, then try again later.');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],

            'contact_no' => [
                'required',
                'regex:/^09\d{9}$/',
                // Live numbers unique; same-email unverified/rejected is archived on store.
                Rule::unique('logistics_providers', 'contact_no')
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

            'business_name' => ['required', 'string', 'max:255'],

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

            'upload_id' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'dti_permit_upload' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.regex' => 'First name can only contain letters, spaces, hyphens and apostrophes.',
            'last_name.regex' => 'Last name can only contain letters, spaces, hyphens and apostrophes.',
            'contact_no.regex' => 'Enter a valid PH mobile number (09XXXXXXXXX).',
            'upload_id.uploaded' => 'Upload ID failed to upload. Use a JPG, PNG, or PDF under 5MB.',
            'upload_id.mimes' => 'Upload ID must be a JPG, PNG, or PDF.',
            'upload_id.max' => 'Upload ID must be smaller than 5MB.',
            'dti_permit_upload.uploaded' => 'DTI permit failed to upload. Use a JPG, PNG, or PDF under 5MB.',
            'dti_permit_upload.mimes' => 'DTI permit must be a JPG, PNG, or PDF.',
            'dti_permit_upload.max' => 'DTI permit must be smaller than 5MB.',
            'street.required' => 'Street / house number is required.',
            'province.required' => 'Select a province.',
            'business_name.required' => 'Enter your business or company name.',
        ];
    }
}
