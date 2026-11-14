<?php

namespace App\Http\Requests\Api\Rider;

use App\Http\Requests\Rider\PickupFailureRequest;
use App\Services\Rider\PickupFailureService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Bearer-token variant of the web pickup-failure request (JSON 422 only).
 *
 * `expected_attempt` is the attempt number the app believes it is recording
 * (the job's `pickup_attempts` + 1). The server compares it under the row
 * lock, so a retried or double-tapped first report is refused with 409
 * instead of being counted as the second (cancelling) attempt.
 */
class ReportPickupFailureApiRequest extends PickupFailureRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'expected_attempt' => ['required', 'integer', 'min:1', 'max:'.PickupFailureService::MAX_ATTEMPTS],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new ValidationException($validator);
    }
}
