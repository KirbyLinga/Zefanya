<?php

namespace App\Http\Requests\Logistics;

use Illuminate\Foundation\Http\FormRequest;

class LinehaulBreakdownPhotoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $provider = $this->user('logistics');
        $breakdown = $this->route('breakdown');

        return $provider instanceof \App\Models\Logistics\LogisticsProvider
            && $breakdown instanceof \App\Models\Logistics\LinehaulBreakdown
            && (new \App\Policies\LinehaulPolicy)->viewBreakdown($provider, $breakdown);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return ['path' => ['prohibited'], 'photo_path' => ['prohibited']];
    }
}
