<?php

namespace App\Http\Requests\Api\Rider;

use App\Http\Requests\Logistics\MarkDeliveryFailedRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

/** Bearer-token variant of the web failed-delivery request (JSON 422 only). */
class MarkDeliveryFailedApiRequest extends MarkDeliveryFailedRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'verification_token' => ['nullable', 'string', 'max:128'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator);
    }
}
