<?php

namespace App\Http\Resources\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LinehaulProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'email_verified' => $this->isEmailVerified(),
            'company' => ['name' => $this->provider->business_name],
            'home_hub' => ['name' => $this->homeHub->name],
        ];
    }
}
