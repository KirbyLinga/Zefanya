<?php

namespace App\Http\Requests\Logistics;

use App\Models\Buyer\Buyer;
use App\Models\Seller\Seller;
use App\Support\EmailRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreLogisticsRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $email = $this->input('email');
            if (is_string($email) && ! $validator->errors()->has('email') && EmailRegistry::otherRolesFor($email, 'logistics') !== []) {
                $validator->errors()->add('email', 'This email is already registered under another account type.');
            }
        }];
    }

    public function rules(): array
    {
        $isManual = $this->input('address_mode') === 'manual';

        return [
            'last_name' => ['required', 'string', 'max:100'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_initial' => ['nullable', 'string', 'max:2'],
            'sex' => ['required', Rule::in(['male', 'female'])],

            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:logistics_providers,email',
                function ($attribute, $value, $fail) {
                    if (Buyer::where('email', $value)->exists()) {
                        $fail('This email is already registered as a buyer. Use a different email.');

                        return;
                    }

                    if (Seller::where('email', $value)->exists()) {
                        $fail('This email is already registered as a seller. Use a different email.');
                    }
                },
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],

            'contact_no' => ['required', 'regex:/^09\d{9}$/', Rule::unique('logistics_providers', 'contact_no')],
            'birthday' => ['required', 'date', 'before:today'],

            'business_name' => ['required', 'string', 'max:255'],

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
            'dti_permit_upload' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_no.unique' => 'This mobile number is already registered.',
            'contact_no.regex' => 'Enter a valid PH mobile number (09XXXXXXXXX).',
            'upload_id.mimes' => 'Upload ID must be a JPG, PNG, or PDF.',
            'upload_id.max' => 'Upload ID must be smaller than 5MB.',
            'dti_permit_upload.mimes' => 'DTI permit must be a JPG, PNG, or PDF.',
            'dti_permit_upload.max' => 'DTI permit must be smaller than 5MB.',
            'street.required' => 'Street / house number is required.',
            'province.required' => 'Select a province, or switch to manual address entry.',
            'business_name.required' => 'Enter your business or company name.',
        ];
    }
}
