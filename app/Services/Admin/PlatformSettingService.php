<?php

namespace App\Services\Admin;

use App\Enums\AdminRole;
use App\Models\Admin\Admin;
use App\Models\Admin\PlatformSetting;
use App\Models\Admin\PlatformSettingHistory;
use App\Services\Logistics\PickupPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlatformSettingService
{
    // Platform metadata, plus the pickup windows and rider pay that Admin approved.
    // Anything else operational must be approved independently before it is added here.
    public const DEFINITIONS = [
        'platform_name' => ['label' => 'Platform name', 'type' => 'text', 'rules' => ['required', 'string', 'max:100']],
        'contact_email' => ['label' => 'Public contact email', 'type' => 'email', 'rules' => ['required', 'string', 'email', 'max:254']],
        // Display text only: shown to Logistics companies on their Earnings page.
        'remittance_payment_details' => ['label' => 'Where Logistics companies send payments', 'type' => 'textarea', 'rules' => ['required', 'string', 'max:1000']],
        // Pickup windows: 24-hour start-end in Philippine time. Used for parcels marked ready after the change.
        PickupPolicy::SETTING_MORNING => ['label' => 'Morning pickup window', 'type' => 'text', 'hint' => '24-hour, Philippine time, like 08:00-12:00', 'rules' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/']],
        PickupPolicy::SETTING_AFTERNOON => ['label' => 'Afternoon pickup window', 'type' => 'text', 'hint' => '24-hour, Philippine time, like 13:00-17:00', 'rules' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/']],
        // Rider pay in pesos. Each earning keeps the rate it was created with.
        PickupPolicy::SETTING_FIRST_RATE => ['label' => 'Rider pay: first parcel of a seller pickup (₱)', 'type' => 'text', 'hint' => 'Paid once per seller visit', 'rules' => ['required', 'string', 'regex:/^\d{1,6}(\.\d{1,2})?$/']],
        PickupPolicy::SETTING_ADDITIONAL_RATE => ['label' => 'Rider pay: each additional parcel in the same pickup (₱)', 'type' => 'text', 'hint' => 'Can be 0', 'rules' => ['required', 'string', 'regex:/^\d{1,6}(\.\d{1,2})?$/']],
        PickupPolicy::SETTING_DELIVERY_RATE => ['label' => 'Rider pay: each delivered parcel (₱)', 'type' => 'text', 'hint' => 'Paid per successful delivery', 'rules' => ['required', 'string', 'regex:/^\d{1,6}(\.\d{1,2})?$/']],
    ];

    public function update(Admin $admin, PlatformSetting $setting, array $input): bool
    {
        abort_unless(in_array($admin->role, [AdminRole::Admin->value, AdminRole::SuperAdmin->value], true), 403);
        abort_unless(isset(self::DEFINITIONS[$setting->key]), 404);

        $data = Validator::make($input, [
            'value' => self::DEFINITIONS[$setting->key]['rules'],
            'expected_value' => ['present', 'nullable', 'string'],
        ])->validate();

        return DB::transaction(function () use ($admin, $setting, $data): bool {
            $current = PlatformSetting::query()->whereKey($setting->id)->lockForUpdate()->firstOrFail();

            // Replayed submissions and unchanged values are not new changes.
            if ($current->value === $data['value']) {
                return false;
            }

            if ($current->value !== (string) $data['expected_value']) {
                throw ValidationException::withMessages([
                    'value' => 'This setting changed since you opened the page. Reload before saving.',
                ]);
            }

            $this->assertPolicy($current->key, $data['value']);

            (new PlatformSettingHistory)->forceFill([
                'platform_setting_id' => $current->id,
                'admin_id' => $admin->id,
                'setting_key' => $current->key,
                'previous_value' => $current->value,
                'new_value' => $data['value'],
            ])->save();

            $current->forceFill(['value' => $data['value']])->save();

            return true;
        }, 3);
    }

    /** Rules that depend on another setting, read while this row is locked. */
    private function assertPolicy(string $key, string $value): void
    {
        $other = fn (string $name, string $default): string => (string) (PlatformSetting::query()->where('key', $name)->value('value') ?? $default);
        $fail = fn (string $message) => throw ValidationException::withMessages(['value' => $message]);

        if (in_array($key, [PickupPolicy::SETTING_MORNING, PickupPolicy::SETTING_AFTERNOON], true)) {
            $window = PickupPolicy::parseWindow($value) ?? $fail('Start the window before it ends, on the same day.');
            $isMorning = $key === PickupPolicy::SETTING_MORNING;
            $partner = PickupPolicy::parseWindow($other(
                $isMorning ? PickupPolicy::SETTING_AFTERNOON : PickupPolicy::SETTING_MORNING,
                (string) config('pickup.windows.'.($isMorning ? 'afternoon' : 'morning')),
            ));
            if ($partner !== null && ($isMorning ? $window[1] > $partner[0] : $partner[1] > $window[0])) {
                $fail('The morning window must end before the afternoon window starts. Windows cannot overlap.');
            }
        }

        if (in_array($key, [PickupPolicy::SETTING_FIRST_RATE, PickupPolicy::SETTING_ADDITIONAL_RATE, PickupPolicy::SETTING_DELIVERY_RATE], true)) {
            $minor = PickupPolicy::parseRate($value) ?? $fail('Enter a peso amount with up to two decimals.');
            if ($key !== PickupPolicy::SETTING_ADDITIONAL_RATE && $minor < 1) {
                $fail('This rate must be more than zero.');
            }
            if ($key === PickupPolicy::SETTING_FIRST_RATE && $minor < (PickupPolicy::parseRate($other(PickupPolicy::SETTING_ADDITIONAL_RATE, '5.00')) ?? 0)) {
                $fail('The first-parcel rate cannot be lower than the additional-parcel rate.');
            }
            if ($key === PickupPolicy::SETTING_ADDITIONAL_RATE && $minor > (PickupPolicy::parseRate($other(PickupPolicy::SETTING_FIRST_RATE, '30.00')) ?? 0)) {
                $fail('The additional-parcel rate cannot be higher than the first-parcel rate.');
            }
        }
    }
}
