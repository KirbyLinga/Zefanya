<?php

namespace App\Http\Requests\Buyer;

use App\Models\Buyer\Buyer;
use App\Support\EmailRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreBuyerRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Trim and lowercase email
        $email = $this->input('email');
        if ($email) {
            $this->merge(['email' => trim(strtolower($email))]);
        }

        // Normalize mobile: convert +63 to 09
        $contactNo = $this->input('contact_no');
        if ($contactNo) {
            $normalized = $this->normalizePhoneNumber($contactNo);
            $this->merge(['contact_no' => $normalized]);
        }
    }

    public function withValidator(Validator $validator): void
    {
        // Custom rule: case-insensitive unique email check
        $validator->after(function ($validator) {
            if ($validator->failed()) {
                return;
            }

            $email = $this->input('email');
            if ($email) {
                // Block only a live registration. A REJECTED applicant (or an
                // abandoned unverified one) must be able to retry with the same
                // email — otherwise a rejection is a permanent dead end.
                $exists = DB::table('buyers')
                    ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                    ->whereNotIn('status', ['unverified', 'rejected'])
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('email', 'The email has already been registered.');
                } elseif ($foreign = EmailRegistry::otherRolesFor($email, 'buyer')) {
                    // One email = one role across every account table.
                    $validator->errors()->add(
                        'email',
                        'This email is already registered as a '.EmailRegistry::labelList($foreign).'. Use a different email.'
                    );
                } else {
                    // Never let a newcomer replace a registration that is still
                    // inside its 10-minute verification window.
                    $stale = Buyer::whereRaw('LOWER(email) = ?', [strtolower($email)])
                        ->where('status', 'unverified')
                        ->first();

                    if ($stale && ! $stale->isAbandonedRegistration()) {
                        $validator->errors()->add(
                            'email',
                            'This email is currently awaiting verification. Please finish verifying it, then try again later.'
                        );
                    }
                }
            }

            // Live contact numbers must be unique. Skip the same-email
            // unverified/rejected row — store() archives it and nulls contact_no.
            $contactNo = $this->input('contact_no');
            if ($contactNo) {
                $exists = DB::table('buyers')
                    ->where('contact_no', $contactNo)
                    ->whereNull('archived_at')
                    ->where(function ($query) use ($email) {
                        $query->whereRaw('LOWER(email) != ?', [strtolower((string) $email)])
                            ->orWhereNotIn('status', ['unverified', 'rejected']);
                    })
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('contact_no', 'This mobile number is already in use.');
                }
            }
        });
    }

    private function normalizePhoneNumber(string $phone): string
    {
        // Remove spaces, hyphens, parentheses
        $phone = preg_replace('/[\s\-()]/u', '', $phone);

        // Convert +63 to 09
        if (str_starts_with($phone, '+63')) {
            $phone = '0'.substr($phone, 3);
        }

        return $phone;
    }

    public function rules(): array
    {
        return [
            'last_name' => ['required', 'string', 'regex:/^[\p{L}\s\-\']+$/u', 'min:2', 'max:50'],
            'first_name' => ['required', 'string', 'regex:/^[\p{L}\s\-\']+$/u', 'min:2', 'max:50'],
            'middle_initial' => ['nullable', 'string', 'max:2'],
            'sex' => ['required', Rule::in(['male', 'female'])],

            'email' => ['required', 'string', 'email', 'max:255', 'regex:/^[^\s@]+@[^\s@]+\.[^\s@]+$/'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*\p{Lu})(?=.*\p{Ll})(?=.*\p{N})(?=.*[\p{Z}\p{S}\p{P}]).+$/u'],

            'contact_no' => ['required', 'regex:/^09\d{9}$/'],
            'birthday' => ['required', 'date', 'before:today', 'before_or_equal:-18 years'],

            // Registration: province / municipality / barangay from dropdowns only.
            'address_mode' => ['required', Rule::in(['api'])],

            'province' => ['required', 'string'],
            'province_name' => ['required', 'string'],
            'municipality' => ['required', 'string'],
            // Without a municipality the order's shipping-address snapshot has
            // none, CourierAssignmentService cannot match a service area, and
            // no rider is ever assigned.
            'municipality_name' => ['required', 'string'],
            'barangay' => ['required', 'string'],
            'barangay_name' => ['required', 'string'],

            'street' => ['required', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'address_detail' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],

            'upload_id' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],

            'terms' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.regex' => 'First name can only contain letters, spaces, hyphens and apostrophes.',
            'last_name.regex' => 'Last name can only contain letters, spaces, hyphens and apostrophes.',
            'first_name.min' => 'First name must be at least 2 characters.',
            'first_name.max' => 'First name must not exceed 50 characters.',
            'last_name.min' => 'Last name must be at least 2 characters.',
            'last_name.max' => 'Last name must not exceed 50 characters.',
            'email.regex' => 'Enter a valid email address with a domain.',
            'password.regex' => 'Password must contain at least one uppercase letter, one lowercase letter, one number and one special character.',
            'contact_no.regex' => 'Enter a valid PH mobile number (09XXXXXXXXX).',
            'contact_no.unique' => 'This mobile number is already in use.',
            'birthday.before_or_equal' => 'You must be at least 18 years old.',
            'upload_id.uploaded' => 'Upload ID failed to upload. Use a JPG, PNG, or PDF under 5MB.',
            'upload_id.mimes' => 'Upload ID must be a JPG, PNG, or PDF.',
            'upload_id.max' => 'Upload ID must be smaller than 5MB.',
            'street.required' => 'Street / house number is required.',
            'province.required' => 'Select a province.',
            'terms.required' => 'You must accept the Terms and Privacy Policy.',
            'terms.accepted' => 'You must accept the Terms and Privacy Policy.',
        ];
    }
}
