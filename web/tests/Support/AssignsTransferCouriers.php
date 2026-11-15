<?php

namespace Tests\Support;

use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\Manifest;
use App\Models\Logistics\ParcelHandoff;
use App\Services\Logistics\TransferCourierService;

trait AssignsTransferCouriers
{
    private function assignTransferCourier(Manifest|ParcelHandoff $transfer, LogisticsProvider $provider): void
    {
        $service = app(TransferCourierService::class);
        $courier = $service->save($provider, [
            'name' => 'Test Transfer Courier', 'contact_no' => '09170000001',
            'vehicle_type' => 'Van', 'plate_number' => 'TEST 100',
        ]);
        if ($transfer instanceof Manifest) {
            $service->assignToManifest($transfer, $provider, $courier->id, null, null);
        } else {
            $service->assignToHandoff($transfer, $provider, $courier->id, null, null);
        }
    }
}
