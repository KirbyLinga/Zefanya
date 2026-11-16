<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Exceptions\CheckoutException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Http\Requests\Api\Buyer\CheckoutPreviewRequest;
use App\Http\Resources\Mobile\CheckoutPreviewResource;
use App\Services\Buyer\CheckoutPreviewService;
use App\Services\Buyer\CodRiskService;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class CheckoutPreviewController extends Controller
{
    use ApiResponses;

    public function __invoke(CheckoutPreviewRequest $request, CheckoutPreviewService $quotes): JsonResponse
    {
        if (app(CodRiskService::class)->isBlocked($request->user())) {
            return $this->error(CodRiskService::BLOCKED_MESSAGE, ['payment_method' => [CodRiskService::BLOCKED_MESSAGE]], 403);
        }

        try {
            $quote = $quotes->quote($request->user(), $request->validated());
        } catch (CheckoutException $exception) {
            throw ValidationException::withMessages(['address_id' => $exception->getMessage()]);
        }

        return $this->success('Checkout quote ready.', new CheckoutPreviewResource($quote));
    }
}
