<?php

namespace App\Http\Requests\Api;

use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\Manifest;
use App\Policies\LinehaulPolicy;
use App\Services\Logistics\LinehaulDelayService;
use Illuminate\Foundation\Http\FormRequest;

class LinehaulDelayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof LinehaulDriver && $this->route('manifest') instanceof Manifest
            && (new LinehaulPolicy)->access($this->user(), $this->route('manifest'));
    }

    public function rules(): array
    {
        return LinehaulDelayService::rules();
    }
}
