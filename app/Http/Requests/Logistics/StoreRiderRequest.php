<?php

namespace App\Http\Requests\Logistics;

use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Rider;
use App\Models\Seller\Seller;
use App\Support\EmailRegistry;
use App\Support\RiderValidation;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRiderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if (is_string($this->input('email'))) {
            $merge['email'] = RiderValidation::normalizeEmail((string) $this->input('email'));
        }
        if (is_string($this->input('contact_no'))) {
            $merge['contact_no'] = RiderValidation::normalizeMobile((string) $this->input('contact_no'));
        }
        if (is_string($this->input('plate_number'))) {
            $merge['plate_number'] = RiderValidation::normalizePlate((string) $this->input('plate_number'));
        }
        if (is_string($this->input('license_no'))) {
            $merge['license_no'] = RiderValidation::normalizeLicense((string) $this->input('license_no'));
        }
        $this->merge($merge);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $providerId = Auth::guard('logistics')->id();

        return array_merge([
            'first_name' => ['required', 'string', 'min:2', 'max:50', 'regex:'.RiderValidation::NAME_REGEX],
            'last_name' => ['required', 'string', 'min:2', 'max:50', 'regex:'.RiderValidation::NAME_REGEX],
            'birthday' => ['required', 'date'],
            'email' => ['required', 'string', 'email', 'max:255', 'regex:/^[^\s@]+@[^\s@]+\.[^\s@]+$/'],
            'contact_no' => ['required', 'regex:/^09\d{9}$/'],
            'license_no' => ['required', 'string', 'regex:'.RiderValidation::LICENSE_REGEX],
            // A licence expiry may be any future date; only an expired date is
            // invalid. Parsed in Asia/Manila so "today" matches the application
            // clock (never the birthday/18+ rule).
            'license_expiry' => [
                'required',
                'bail',
                'date',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! RiderValidation::isExpiryValid((string) $value)) {
                        $fail('The driving license is expired.');
                    }
                },
            ],
            'vehicle_type' => ['required', Rule::in(RiderValidation::VEHICLES)],
            'plate_number' => ['required', 'string', 'max:20'],
            'drivers_license' => ['required', 'file', 'max:5120'],
            'or_cr' => ['required', 'file', 'max:5120'],
            // Legacy Logistics-created riders must be attached to one of the
            // company's approved, active Station Hubs before they can activate.
            'station_hub_id' => [
                'required', 'integer',
                Rule::exists('logistics_hubs', 'id')
                    ->where('logistics_provider_id', $providerId)
                    ->where('hub_type', LogisticsHub::STATION)
                    ->where('approval_status', LogisticsHub::APPROVAL_APPROVED)
                    ->where('is_active', true),
            ],
        ], $this->addressRules(required: false));
    }

    /**
     * Residential address collected by registration. Logistics-created riders
     * may omit it; self-registration (StoreRiderRegistrationRequest) requires
     * it. Riders never choose a station/coverage here.
     *
     * @return array<string, mixed>
     */
    protected function addressRules(bool $required): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            'address_mode' => [$required ? 'required' : 'nullable', Rule::in(['api', 'manual'])],
            'province' => [$presence, 'string', 'max:20'],
            'province_name' => [$presence, 'string', 'max:120'],
            'municipality' => [$presence, 'string', 'max:20'],
            'municipality_name' => [$presence, 'string', 'max:120'],
            'barangay' => [$presence, 'string', 'max:20'],
            'barangay_name' => [$presence, 'string', 'max:120'],
            'street' => [$presence, 'string', 'max:255'],
            'house_number' => ['nullable', 'string', 'max:50'],
            'address_detail' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:10'],
        ];
    }

    /** Allowed upload extensions (spec: JPG, PNG, PDF). */
    private const UPLOAD_EXTENSIONS = ['jpg', 'jpeg', 'png', 'pdf'];

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            if ($v->errors()->any()) {
                return;
            }
            if (! $v->errors()->has('birthday') && $this->filled('birthday') && ! RiderValidation::isAdult((string) $this->input('birthday'))) {
                $v->errors()->add('birthday', 'The rider must be at least 18 years old.');
            }
            $email = strtolower((string) $this->input('email', ''));
            if ($email !== '' && ! $v->errors()->has('email')) {
                $taken = EmailRegistry::rolesFor($email) !== [];
                if ($taken) {
                    $v->errors()->add('email', 'This email is already in use.');
                }
            }
            $mobile = (string) $this->input('contact_no', '');
            if ($mobile !== '' && ! $v->errors()->has('contact_no')) {
                $taken = Rider::where('contact_no', $mobile)->exists()
                    || Buyer::where('contact_no', $mobile)->exists()
                    || Seller::where('contact_no', $mobile)->exists()
                    || LogisticsProvider::where('contact_no', $mobile)->exists();
                if ($taken) {
                    $v->errors()->add('contact_no', 'This mobile number is already in use.');
                }
            }
            $lic = (string) $this->input('license_no', '');
            if ($lic !== '' && ! $v->errors()->has('license_no') && Rider::where('license_no', $lic)->exists()) {
                $v->errors()->add('license_no', $this->licenseTakenMessage());
            }
            $plate = (string) $this->input('plate_number', '');
            if ($plate !== '' && ! $v->errors()->has('plate_number') && Rider::where('plate_number', $plate)->exists()) {
                $v->errors()->add('plate_number', 'This plate number is already registered.');
            }
            foreach (['drivers_license', 'or_cr'] as $field) {
                if ($v->errors()->has($field)) {
                    continue;
                }
                $file = $this->file($field);
                if (! $file || ! $file->isValid()) {
                    continue;
                }
                $ext = strtolower((string) $file->getClientOriginalExtension());
                if (! in_array($ext, self::UPLOAD_EXTENSIONS, true)) {
                    $v->errors()->add($field, $field === 'drivers_license' ? 'Driver license must be a JPG, PNG, or PDF.' : 'Vehicle OR/CR must be a JPG, PNG, or PDF.');

                    continue;
                }
                $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 12);
                $ok = str_starts_with($head, "\xFF\xD8\xFF") || str_starts_with($head, "\x89PNG\r\n\x1A\n") || str_starts_with($head, '%PDF');
                if (! $ok) {
                    $v->errors()->add($field, $field === 'drivers_license' ? 'Driver license content must be a real JPG, PNG, or PDF.' : 'OR/CR content must be a real JPG, PNG, or PDF.');
                }
            }
        });
    }

    /**
     * Message shown when the licence number is already on file. Self-service
     * rider registration overrides this to point at the resend-code recovery.
     */
    protected function licenseTakenMessage(): string
    {
        return 'This license number is already registered.';
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'first_name.regex' => 'First name may contain only letters, spaces, hyphen, apostrophe, and period.',
            'first_name.min' => 'First name must be at least 2 characters.',
            'first_name.max' => 'First name must not exceed 50 characters.',
            'last_name.regex' => 'Last name may contain only letters, spaces, hyphen, apostrophe, and period.',
            'last_name.min' => 'Last name must be at least 2 characters.',
            'last_name.max' => 'Last name must not exceed 50 characters.',
            'email.email' => 'Enter a valid email address.',
            'email.regex' => 'Enter a valid email address with a domain.',
            'contact_no.regex' => 'Enter a valid mobile number (09XXXXXXXXX or +639XXXXXXXXX).',
            'license_no.regex' => 'License number must follow the format A00-00-000000.',
            'license_expiry.date' => 'Enter a valid license expiry date.',
            'vehicle_type.in' => 'Vehicle type must be Motorcycle, Car, or Van.',
            'drivers_license.max' => 'Driver license must not exceed 5 MB.',
            'or_cr.max' => 'Vehicle OR/CR must not exceed 5 MB.',
            'station_hub_id.required' => 'Select an active Station Hub for this rider.',
            'station_hub_id.exists' => 'Select an approved, active Station Hub owned by your company.',
            'province.required' => 'Select a province.',
            'municipality.required' => 'Select a city or municipality.',
            'barangay.required' => 'Select a barangay.',
            'street.required' => 'Enter the street address.',
        ];
    }
}
