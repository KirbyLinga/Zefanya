<?php

namespace App\Http\Requests\Seller;

use App\Models\Seller\Seller;
use App\Support\BirthDate;
use App\Support\DocumentUpload;
use App\Support\EmailRegistry;
use App\Support\PasswordRule;
use App\Support\PersonName;
use App\Support\PhilippineMobile;
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
            $this->merge(['email' => EmailRegistry::normalize($email)]);
        }

        $contactNo = $this->input('contact_no');
        if (is_string($contactNo)) {
            $this->merge(['contact_no' => PhilippineMobile::normalize($contactNo)]);
        }
    }

    public function rules(): array
    {
        return [
            'last_name' => PersonName::rules(),
            'first_name' => PersonName::rules(),
            'middle_initial' => ['nullable', 'string', 'max:2', 'regex:/^[\p{L}\.]+$/u'],
            'sex' => ['required', Rule::in(['male', 'female'])],

            'email' => [
                ...EmailRegistry::formatRules(),
                function ($attribute, $value, $fail) {
                    if ($error = EmailRegistry::registrationError($value, 'seller', submission: $this->only(['password', 'first_name', 'last_name', 'contact_no']))) {
                        $fail($error);
                    }
                },
            ],
            // Same rule as the registration modal (registration-validation.js).
            'password' => PasswordRule::rules(),

            'contact_no' => [
                'bail',
                ...PhilippineMobile::rules(),
                // Unique across every role; same-email unverified/rejected is archived on store.
                function ($attribute, $value, $fail) {
                    if (PhilippineMobile::isTaken($value, 'seller', (string) $this->input('email'))) {
                        $fail(PhilippineMobile::TAKEN_MESSAGE);
                    }
                },
            ],
            'birthday' => BirthDate::rules(),

            // Registration: province / municipality / barangay from dropdowns only.
            'address_mode' => ['required', Rule::in(['api'])],
            'region_code' => ['required', 'string', 'regex:/\A[0-9]{9,10}\z/'],

            'province' => ['required', 'string'],
            'province_name' => ['required', 'string'],
            'municipality' => ['required', 'string'],
            'municipality_name' => ['required', 'string'],
            'barangay' => ['required', 'string'],
            'barangay_name' => ['required', 'string'],

            'street' => ['required', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'address_detail' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'regex:/\A[0-9]{4}\z/'],

            'business_name' => [
                'bail', 'required', 'string', 'min:3', 'max:30',
                function ($attribute, $value, $fail) {
                    $taken = Seller::withoutGlobalScope('not_archived')
                        ->whereRaw('LOWER(email) != ?', [(string) $this->input('email')])
                        ->get(['business_name'])
                        ->contains(fn ($seller) => Seller::normalizedStoreName($seller->business_name) === Seller::normalizedStoreName($value));
                    if ($taken) {
                        $fail('This shop name is already taken.');
                    }
                },
            ],
            'tin' => ['bail', 'required', 'string', 'regex:/\A[0-9]{3}-[0-9]{3}-[0-9]{3}-[0-9]{3}\z/',
                Rule::unique('sellers', 'tin')->where(fn ($query) => $query->whereRaw('LOWER(email) != ?', [(string) $this->input('email')])),
            ],
            'line_of_business_id' => ['required', 'integer', 'exists:categories,id'],

            'seller_type' => ['required', Rule::in(['sole_proprietor', 'corporation'])],
            'sec_certificate' => [Rule::excludeIf(fn () => $this->input('seller_type') !== 'corporation'), ...DocumentUpload::rules()],
            'upload_id' => DocumentUpload::rules(),
            'business_permit' => DocumentUpload::rules(),

            'terms' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_name.min' => 'Shop name must be 3 to 30 characters.',
            'business_name.max' => 'Shop name must be 3 to 30 characters.',
            'tin.required' => 'Enter your TIN in this format: 000-000-000-000.',
            'tin.regex' => 'Enter your TIN in this format: 000-000-000-000.',
            'tin.unique' => 'This TIN is already registered.',
            'first_name.required' => 'First name is required.',
            ...PersonName::messages('first_name', 'First name'),
            'last_name.required' => 'Last name is required.',
            ...PersonName::messages('last_name', 'Last name'),
            'middle_initial.regex' => 'Middle initial must contain only letters and periods.',
            'email.email' => 'Enter a valid email address.',
            'email.regex' => EmailRegistry::FORMAT_MESSAGE,
            'email.unique' => 'This email is already registered. Use a different email.',
            ...PasswordRule::messages(),
            ...BirthDate::messages(),
            'postal_code.regex' => 'Postal code must contain exactly 4 digits.',
            'region_code.required' => 'Select a region.',
            'seller_type.required' => 'Select your seller type.',
            'sec_certificate.required' => 'Upload an SEC certificate for a corporation.',
            ...DocumentUpload::messages('sec_certificate', 'SEC certificate'),
            ...DocumentUpload::messages('upload_id', 'Upload ID'),
            ...DocumentUpload::messages('business_permit', 'Business permit'),
            'terms.required' => 'You must accept the Terms and Privacy Policy.',
            'terms.accepted' => 'You must accept the Terms and Privacy Policy.',
            'street.required' => 'Street / house number is required.',
            'province.required' => 'Select a province.',
            'line_of_business_id.required' => 'Select your line of business.',
        ];
    }
}
