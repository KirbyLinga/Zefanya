<?php

namespace App\Http\Controllers\Api\Courier;

use App\Http\Controllers\Controller;
use App\Http\Requests\Courier\StoreCourierRegistrationRequest;
use App\Services\Auth\CourierRegistrationService;
use Illuminate\Http\JsonResponse;

class CourierRegistrationController extends Controller
{
    public function __construct(
        protected CourierRegistrationService $registrationService
    ) {}

    /**
     * POST /api/register/courier
     * POST /api/register/logistics
     * POST /api/v1/courier/register
     */
    public function store(StoreCourierRegistrationRequest $request): JsonResponse
    {
        $courier = $this->registrationService->register(
            $request->validated(),
            $request->allFiles()
        );

        return response()->json([
            'ok' => true,
            'role' => 'courier',
            'name' => $courier->fullName(),
            'email' => $courier->email,
            'registration_status' => 'pending_approval',
            'message' => 'Application submitted for approval. You will be notified once reviewed.',
        ], 201);
    }
}
