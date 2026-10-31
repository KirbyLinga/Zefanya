<?php

namespace App\Services\Admin;

use App\Enums\AdminRole;
use App\Models\Admin\Admin;
use App\Models\Admin\PlatformSetting;
use App\Models\Admin\PlatformSettingHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlatformSettingService
{
    // Only non-operational metadata is editable in RULE-01. Policy settings
    // must be approved independently before being added or consumed anywhere.
    public const DEFINITIONS = [
        'platform_name' => ['label' => 'Platform name', 'type' => 'text', 'rules' => ['required', 'string', 'max:100']],
        'contact_email' => ['label' => 'Public contact email', 'type' => 'email', 'rules' => ['required', 'string', 'email', 'max:254']],
        // Display text only: shown to Logistics companies on their Earnings page.
        'remittance_payment_details' => ['label' => 'Where Logistics companies send payments', 'type' => 'textarea', 'rules' => ['required', 'string', 'max:1000']],
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
}
