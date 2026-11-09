<?php

namespace App\Http\Requests\Api;

use App\Services\Logistics\LinehaulBreakdownService;

class LinehaulBreakdownRequest extends LinehaulDelayRequest
{
    public function rules(): array
    {
        return LinehaulBreakdownService::rules();
    }
}
