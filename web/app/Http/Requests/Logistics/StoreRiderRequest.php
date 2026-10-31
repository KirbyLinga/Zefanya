<?php

namespace App\Http\Requests\Logistics;

use App\Models\Logistics\LogisticsHub;
use App\Models\Rider\Rider;
use App\Support\BirthDate;
use App\Support\DocumentUpload;
use App\Support\EmailRegistry;
use App\Support\PersonName;
use App\Support\PhilippineMobile;
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
            'first_name' => PersonName::rules(),
            'last_name' => PersonName::rules(),
            'birthday' => BirthDate::rules(),
            'email' => EmailRegistry::formatRules(),
            'contact_no' => PhilippineMobile::rules(),
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
            'drivers_license' => DocumentUpload::rules(),
            'or_cr' => DocumentUpload::rules(),
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
            $email = strtolower((string) $this->input('email', ''));
            if ($email !== '' && ! $v->errors()->has('email')) {
                $taken = EmailRegistry::rolesFor($email) !== [];
                if ($taken) {
                    $v->errors()->add('email', 'This email is already in use.');
                }
            }
            $mobile = (string) $this->input('contact_no', '');
            if ($mobile !== '' && ! $v->errors()->has('contact_no')) {
                if (PhilippineMobile::isTaken($mobile, 'rider')) {
                    $v->errors()->add('contact_no', PhilippineMobile::TAKEN_MESSAGE);
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
                    $v->errors()->add($field, DocumentUpload::message($field === 'drivers_license' ? 'Driver\'s license' : 'Vehicle OR/CR'));

                    continue;
                }
                $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 12);
                $ok = str_starts_with($head, "\xFF\xD8\xFF") || str_starts_with($head, "\x89PNG\r\n\x1A\n") || str_starts_with($head, '%PDF');
                if (! $ok) {
                    $v->errors()->add($field, DocumentUpload::message($field === 'drivers_license' ? 'Driver\'s license' : 'Vehicle OR/CR'));
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
            ...BirthDate::messages(),
            ...PersonName::messages('first_name', 'First name'),
            ...PersonName::messages('last_name', 'Last name'),
            'email.email' => 'Enter a valid email address.',
            'email.regex' => EmailRegistry::FORMAT_MESSAGE,
            'license_no.regex' => 'License number must follow the format A00-00-000000.',
            'license_expiry.date' => 'Enter a valid license expiry date.',
            'vehicle_type.in' => 'Vehicle type must be Motorcycle, Car, or Van.',
            ...DocumentUpload::messages('drivers_license', 'Driver\'s license'),
            ...DocumentUpload::messages('or_cr', 'Vehicle OR/CR'),
            'station_hub_id.required' => 'Select an active Station Hub for this rider.',
            'station_hub_id.exists' => 'Select an approved, active Station Hub owned by your company.',
            'province.required' => 'Select a province.',
            'municipality.required' => 'Select a city or municipality.',
            'barangay.required' => 'Select a barangay.',
            'street.required' => 'Enter the street address.',
        ];
    }
}
