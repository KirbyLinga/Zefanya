<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;

class CheckoutPreviewController extends Controller
{
    use \App\Http\Middleware\Traits\ApiResponses;

    public function __invoke(\App\Http\Requests\Api\Buyer\CheckoutPreviewRequest $request, \App\Services\Buyer\CheckoutPreviewService $quotes): \Illuminate\Http\JsonResponse
    {
        try {
            $quote = $quotes->quote($request->user(), $request->validated());
        } catch (\App\Exceptions\CheckoutException $exception) {
            throw \Illuminate\Validation\ValidationException::withMessages(['address_id' => $exception->getMessage()]);
        }

        return $this->success('Checkout quote ready.', new \App\Http\Resources\Mobile\CheckoutPreviewResource($quote));
    }
}
