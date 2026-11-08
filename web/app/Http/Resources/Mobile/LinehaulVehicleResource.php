<?php

namespace App\Http\Resources\Mobile;

use App\Services\Logistics\LinehaulDutyService;
use App\Services\Logistics\LinehaulProfileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LinehaulVehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $installed = LinehaulProfileService::installed();
        $profile = LinehaulProfileService::current($this->resource);
        $base = $request->is('api/*') ? '/api/courier/vehicle/documents/' : '/logistics/couriers/'.$this->id.'/vehicle/documents/';

        return ['driver_id' => $this->id, 'name' => $this->name, 'status' => $this->status,
            'setup_required' => ! $installed, 'editable' => $installed && ! $this->on_duty && ! LinehaulDutyService::busy($this->resource),
            'profile' => $profile === null ? null : [
                'version' => $profile->version, 'plate_number' => $profile->plate_number,
                'license_no' => $profile->license_no, 'license_type' => 'Professional',
                'license_expiry' => $profile->license_expiry->toDateString(), 'license_codes' => $profile->license_codes,
                'vehicle_type' => $profile->vehicle_type, 'capacity_kg' => $profile->capacity_kg,
                'review_status' => $profile->review_status, 'review_note' => $profile->review_note,
                'documents' => collect($profile->documents)->map(fn ($document, $key) => [
                    'mime' => $document['mime'], 'size' => $document['size'], 'download_path' => $base.$key,
                ])->all(),
            ]];
    }
}
