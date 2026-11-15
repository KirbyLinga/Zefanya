<?php

namespace App\Http\Requests\Api\Rider;

use App\Http\Requests\Rider\CompleteDeliveryRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Bearer-token variant of the web proof-of-delivery request.
 *
 * Same rules and messages (photo type/size, COD peso format) as
 * the web form; adds the scan verification token and always answers with a
 * 422 JSON body instead of a redirect.
 */
class CompleteDeliveryApiRequest extends CompleteDeliveryRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'verification_token' => ['nullable', 'string', 'max:128'],
            'physical_handover' => ['required', 'accepted'],
            'recipient_accepted' => ['required', 'accepted'],
            'recipient_category' => ['required', 'in:buyer,representative'],
            'recipient_relationship' => ['nullable', 'required_if:recipient_category,representative', 'string', 'max:120'],
            'received_by' => ['nullable', 'required_if:recipient_category,representative', 'string', 'max:120'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator);
    }
}
