<?php

namespace App\Http\Requests\Api;

use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\Manifest;
use App\Policies\LinehaulPolicy;
use App\Services\Logistics\LinehaulDeclineService;
use Illuminate\Foundation\Http\FormRequest;

class LinehaulDeclineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof LinehaulDriver && $this->route('manifest') instanceof Manifest
            && (new LinehaulPolicy)->decline($this->user(), $this->route('manifest'));
    }

    public function rules(): array
    {
        return LinehaulDeclineService::rules();
    }
}
