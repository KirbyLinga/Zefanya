<?php

return [
    'require_reviewed_vehicle' => filter_var(env('LINEHAUL_REQUIRE_REVIEWED_VEHICLE', false), FILTER_VALIDATE_BOOLEAN),
    'require_hub_geofence' => filter_var(env('LINEHAUL_REQUIRE_HUB_GEOFENCE', false), FILTER_VALIDATE_BOOLEAN),
    'hub_coordinates' => json_decode((string) env('LINEHAUL_HUB_COORDINATES', '{}'), true),
    'ten_wheeler_pay_minor' => env('LINEHAUL_TEN_WHEELER_PAY_MINOR'),
    'push_project' => env('LINEHAUL_FCM_PROJECT'),
    'push_credentials_path' => env('LINEHAUL_FCM_CREDENTIALS_PATH'),
];
