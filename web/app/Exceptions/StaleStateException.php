<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The action was valid when the page rendered, but the record changed before
 * the request reached its row lock (e.g. Buyer Cancel after Seller Accept).
 *
 * Extends InvalidArgumentException so every existing `catch` around
 * SellerOrderStatusService keeps working. JSON callers get HTTP 409; browser
 * forms are sent back to the freshly rendered page with the message.
 */
class StaleStateException extends InvalidArgumentException
{
    /**
     * Stale-state rejection for services that report through validation
     * errors: same redirect-with-errors for forms, 409 instead of 422 for JSON.
     */
    public static function field(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => $message])->status(Response::HTTP_CONFLICT);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json([
                'message' => $this->getMessage(),
                'errors' => ['status' => [$this->getMessage()]],
            ], Response::HTTP_CONFLICT);
        }

        return back()->with('error', $this->getMessage());
    }
}
