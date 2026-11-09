<?php

namespace App\Http\Requests\Api;

use App\Models\Logistics\LinehaulDriver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;

class LinehaulPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        $driver = $this->user();

        return $driver instanceof LinehaulDriver && $driver->currentAccessToken() instanceof PersonalAccessToken
            && Gate::forUser($driver)->allows('linehaul-access');
    }

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'device_token' => [
                'required_if:enabled,true,1', 'prohibited_if:enabled,false,0',
                'string', 'min:20', 'max:4096', 'regex:/\A[A-Za-z0-9_:\-]+\z/',
            ],
        ];
    }
}
