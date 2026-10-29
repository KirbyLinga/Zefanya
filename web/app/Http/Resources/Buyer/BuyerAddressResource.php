<?php

namespace App\Http\Resources\Buyer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BuyerAddressResource extends JsonResource
{
    /**
     * Transform the resource into an array matching mobile AddressEntry.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'recipient' => $this->recipient,
            'phone' => $this->phone,
            'label' => $this->label ?: 'Home',
            'region' => $this->region,
            'regionCode' => $this->region_code,
            'province' => $this->province ?: ($this->region === 'National Capital Region (NCR)' ? 'Metro Manila' : ''),
            'provinceCode' => $this->province_code,
            'city' => $this->city,
            'cityCode' => $this->city_code,
            'barangay' => $this->barangay,
            'barangayCode' => $this->barangay_code,
            'street' => $this->street,
            'postalCode' => (string) ($this->postal_code ?? ''),
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,
            'isDefault' => (bool) $this->is_default,
            'summary' => $this->formattedSummary(),
        ];
    }
}
