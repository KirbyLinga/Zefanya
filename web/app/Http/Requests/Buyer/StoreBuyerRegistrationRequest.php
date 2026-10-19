<?php

namespace App\Http\Requests\Buyer;

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
                $exists = DB::table('buyers')
                    ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                    ->exists();

                if ($exists) {
                    $validator->errors()->add('email', 'The email has already been registered.');
                }
            }

            // Custom rule: unique mobile number check (both formats)
            $contactNo = $this->input('contact_no');
            if ($contactNo) {
                $exists = DB::table('buyers')
                    ->where('contact_no', $contactNo)
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

    public function after(): array
    {
        return [function (Validator $validator): void {
            $email = $this->input('email');
            if (is_string($email) && ! $validator->errors()->has('email') && EmailRegistry::otherRolesFor($email, 'buyer') !== []) {
                $validator->errors()->add('email', 'This email is already registered under another account type.');
            }
        }];
    }

    public function rules(): array
    {
        $isManual = $this->input('address_mode') === 'manual';

        return [
            'last_name' => ['required', 'string', 'regex:/^[\p{L}\s\-\']+$/u', 'min:2', 'max:50'],
            'first_name' => ['required', 'string', 'regex:/^[\p{L}\s\-\']+$/u', 'min:2', 'max:50'],
            'middle_initial' => ['nullable', 'string', 'max:2'],
            'sex' => ['required', Rule::in(['male', 'female'])],

            'email' => ['required', 'string', 'email', 'max:255', 'regex:/^[^\s@]+@[^\s@]+\.[^\s@]+$/'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'regex:/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[!@#$%^&*()_+\-=\[\]{}|;:\'",.<>?\\/]).{8,}$/'],

            'contact_no' => ['required', 'regex:/^09\d{9}$/'],
            'birthday' => ['required', 'date', 'before:today', 'before_or_equal:-18 years'],

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
            'upload_id.mimes' => 'Upload ID must be a JPG, PNG, or PDF.',
            'upload_id.max' => 'Upload ID must be smaller than 5MB.',
            'street.required' => 'Street / house number is required.',
            'province.required' => 'Select a province, or switch to manual address entry.',
            'terms.required' => 'You must accept the Terms and Privacy Policy.',
            'terms.accepted' => 'You must accept the Terms and Privacy Policy.',
        ];
    }
}
