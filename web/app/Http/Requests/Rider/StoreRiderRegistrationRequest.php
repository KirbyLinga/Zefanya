<?php

namespace App\Http\Requests\Rider;

use App\Models\Courier;
use App\Models\RiderApplication;
use App\Support\EmailRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\File\File;

class StoreRiderRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => strtolower(trim($this->input('email')))]);
        }
        if (is_string($this->input('contact_no'))) {
            $phone = preg_replace('/[\s\-()]/u', '', $this->input('contact_no'));
            if (str_starts_with($phone, '+63')) {
                $phone = '0'.substr($phone, 3);
            }
            $this->merge(['contact_no' => $phone]);
        }
        if (is_string($this->input('plate_number'))) {
            $this->merge(['plate_number' => strtoupper(trim($this->input('plate_number')))]);
        }
    }

    public function rules(): array
    {
        $document = ['required', 'file', 'mimes:jpg,jpeg,png,pdf',  'extensions:jpg,jpeg,png,pdf', 'max:5120', function ($attribute, $file, $fail): void {
            if ($file instanceof UploadedFile && $file->isValid()) {
                $mime = (new File($file->getRealPath()))->getMimeType();
                if (! in_array($mime, ['image/jpeg', 'image/png', 'application/pdf'], true)) {
                    $fail('The document content must be a JPG, PNG, or PDF.');
                }
            }
        }];

        return [
            'first_name' => ['required', 'string', "regex:/^[\p{L}\s'-]+$/u", 'max:100'],
            'last_name' => ['required', 'string', "regex:/^[\p{L}\s'-]+$/u", 'max:100'],
            'middle_initial' => ['nullable', 'string', 'regex:/^\p{L}{1,2}$/u'],
            'sex' => ['required', Rule::in(['male', 'female'])],
            'email' => ['bail', 'required', 'string', 'email:rfc', 'max:255', function ($attribute, $value, $fail) {
                if (EmailRegistry::otherRolesFor($value, 'rider') !== []) {
                    $fail('This email is already registered under another account type.');
                }
                $existing = RiderApplication::whereRaw('LOWER(email) = ?', [strtolower($value)])->first();
                if ($existing && ! ($existing->status === 'rejected' || ($existing->status === 'unverified' && $existing->isAbandonedRegistration()))) {
                    $fail('This email is already registered. Please finish verification or sign in.');
                }
            }],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()->symbols(), 'confirmed'],
            'contact_no' => ['bail', 'required', 'string', 'regex:/^09\d{9}$/', Rule::unique('rider_applications', 'contact_no')->where(function ($query) {
                $query->whereNull('archived_at')->whereRaw('LOWER(email) != ?', [is_string($this->input('email')) ? $this->input('email') : '']);
            })],
            // Same adult-registration convention as the shared birthday validator.
            'birthday' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.now()->subYears(18)->toDateString()],
            'logistics_provider_id' => ['required', 'integer', Rule::exists('logistics_providers', 'id')->where('status', 'approved')],
            'address_mode' => ['required', Rule::in(['api'])],
            'province' => ['required', 'string', 'max:20'],
            'province_name' => ['required', 'string', 'max:255'],
            'municipality' => ['required', 'string', 'max:20'],
            'municipality_name' => ['required', 'string', 'max:255'],
            'barangay' => ['required', 'string', 'max:20'],
            'barangay_name' => ['required', 'string', 'max:255'],
            'street' => ['required', 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'address_detail' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
            'vehicle_type' => ['required', Rule::in(Courier::VEHICLE_TYPES)],
            'plate_number' => ['bail', 'required', 'string', 'max:20', 'regex:/^[A-Z0-9]+(?:[ -][A-Z0-9]+)*$/', 'regex:/[0-9]/'],
            'upload_id' => $document,
            'or_cr_upload' => $document,
            'terms' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'contact_no.regex' => 'Enter a valid PH mobile number (09XXXXXXXXX).',
            'birthday.before_or_equal' => 'You must be at least 18 years old to register.',
            'logistics_provider_id.exists' => 'Select an approved Logistics provider.',
            'plate_number.regex' => 'Enter a plate number using letters, numbers, spaces or hyphens, including at least one number.',
            'terms.accepted' => 'You must accept the Terms and Privacy Policy.',
        ];
    }
}
