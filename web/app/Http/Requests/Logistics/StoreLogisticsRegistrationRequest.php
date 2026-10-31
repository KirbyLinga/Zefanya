<?php

namespace App\Http\Requests\Logistics;

use App\Models\Logistics\LogisticsProvider;
use App\Support\BirthDate;
use App\Support\DocumentUpload;
use App\Support\EmailRegistry;
use App\Support\PasswordRule;
use App\Support\PersonName;
use App\Support\PhilippineMobile;
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
        if (is_string($this->input('sec_number'))) {
            $this->merge(['sec_number' => strtoupper(trim($this->input('sec_number')))]);
        }
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
            // Shared name rule for every role (see PersonName).
            'last_name' => PersonName::rules(),
            'first_name' => PersonName::rules(),
            'middle_initial' => ['nullable', 'string', 'max:2'],
            'sex' => ['required', Rule::in(['male', 'female'])],

            'email' => [
                ...EmailRegistry::formatRules(),
                function ($attribute, $value, $fail) {
                    if ($error = EmailRegistry::registrationError($value, 'logistics', submission: $this->only(['password', 'first_name', 'last_name', 'contact_no']))) {
                        $fail($error);
                    }
                },
            ],
            'password' => PasswordRule::rules(),

            'contact_no' => [
                'bail',
                ...PhilippineMobile::rules(),
                // Unique across every role; same-email unverified/rejected is archived on store.
                function ($attribute, $value, $fail) {
                    if (PhilippineMobile::isTaken($value, 'logistics', (string) $this->input('email'))) {
                        $fail(PhilippineMobile::TAKEN_MESSAGE);
                    }
                },
            ],
            'birthday' => BirthDate::rules(),

            'business_name' => ['bail', 'required', 'string', 'max:255', function ($attribute, $value, $fail) {
                $taken = LogisticsProvider::withoutGlobalScope('not_archived')
                    ->whereRaw('LOWER(email) != ?', [(string) $this->input('email')])
                    ->pluck('business_name')->contains(fn ($name) => LogisticsProvider::normalizedCompanyName($name) === LogisticsProvider::normalizedCompanyName($value));
                if ($taken) {
                    $fail('This company name is already registered.');
                }
            }],
            'sec_number' => ['bail', 'required', 'string', 'regex:/\A[A-Z]{2}[0-9]{9}\z/',
                Rule::unique('logistics_providers', 'sec_number')->where(fn ($query) => $query->whereRaw('LOWER(email) != ?', [(string) $this->input('email')])),
            ],
            'service_areas' => ['required', 'array', 'min:1', 'max:100'],
            'service_areas.*' => ['required', 'string', 'distinct', 'regex:/\A[0-9]{9,10}\z/'],
            'tin' => ['bail', 'required', 'string', 'regex:/\A[0-9]{3}-[0-9]{3}-[0-9]{3}-[0-9]{3}\z/',
                Rule::unique('logistics_providers', 'tin')->where(fn ($query) => $query->whereRaw('LOWER(email) != ?', [(string) $this->input('email')])),
            ],

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

            'upload_id' => DocumentUpload::rules(),
            'dti_permit_upload' => DocumentUpload::rules(),

            'terms' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'postal_code.regex' => 'Postal code must contain exactly 4 digits.',
            'region_code.required' => 'Select a region.',
            'sec_number.regex' => 'SEC number must contain 2 letters followed by 9 digits.',
            'sec_number.unique' => 'This SEC number is already registered.',
            'service_areas.required' => 'Select at least one service area.',
            'service_areas.*.distinct' => 'Select each service area only once.',
            'tin.required' => 'Enter your TIN in this format: 000-000-000-000.',
            'tin.regex' => 'Enter your TIN in this format: 000-000-000-000.',
            'tin.unique' => 'This TIN is already registered.',
            'email.email' => 'Enter a valid email address.',
            'email.regex' => EmailRegistry::FORMAT_MESSAGE,
            ...PasswordRule::messages(),
            ...BirthDate::messages(),
            ...PersonName::messages('first_name', 'First name'),
            ...PersonName::messages('last_name', 'Last name'),
            ...DocumentUpload::messages('upload_id', 'Upload ID'),
            ...DocumentUpload::messages('dti_permit_upload', 'DTI permit'),
            'terms.required' => 'You must accept the Terms and Privacy Policy.',
            'terms.accepted' => 'You must accept the Terms and Privacy Policy.',
            'street.required' => 'Street / house number is required.',
            'province.required' => 'Select a province.',
            'business_name.required' => 'Enter your business or company name.',
        ];
    }
}
