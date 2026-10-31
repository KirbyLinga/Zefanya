<?php

namespace App\Http\Requests\Buyer;

use App\Support\BirthDate;
use App\Support\EmailRegistry;
use App\Support\PasswordRule;
use App\Support\PersonName;
use App\Support\PhilippineMobile;
use Illuminate\Foundation\Http\FormRequest;
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
            $this->merge(['email' => EmailRegistry::normalize($email)]);
        }

        // Normalize mobile: +639XXXXXXXXX → 09XXXXXXXXX before the unique check.
        $contactNo = $this->input('contact_no');
        if (is_string($contactNo)) {
            $this->merge(['contact_no' => PhilippineMobile::normalize($contactNo)]);
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
            if ($email && ($error = EmailRegistry::registrationError($email, 'buyer', submission: $this->only(['password', 'first_name', 'last_name', 'contact_no'])))) {
                $validator->errors()->add('email', $error);
            }

            // Live contact numbers are unique across every role.
            $contactNo = $this->input('contact_no');
            if (is_string($contactNo) && PhilippineMobile::isTaken($contactNo, 'buyer', (string) $email)) {
                $validator->errors()->add('contact_no', PhilippineMobile::TAKEN_MESSAGE);
            }
        });
    }

    public function rules(): array
    {
        return [
            'last_name' => PersonName::rules(),
            'first_name' => PersonName::rules(),
            'middle_initial' => ['nullable', 'string', 'max:2'],
            'sex' => ['required', Rule::in(['male', 'female'])],

            'email' => EmailRegistry::formatRules(),
            'password' => PasswordRule::rules(),

            'contact_no' => PhilippineMobile::rules(),
            'birthday' => BirthDate::rules(),

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
            ...PersonName::messages('first_name', 'First name'),
            ...PersonName::messages('last_name', 'Last name'),
            'email.email' => 'Enter a valid email address.',
            'email.regex' => EmailRegistry::FORMAT_MESSAGE,
            ...PasswordRule::messages(),
            ...BirthDate::messages(),
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
