<?php

namespace App\Http\Middleware\Traits;

/** Shared JSON response helpers for API controllers.
 *
 * The application uses a consistent JSON shape for every API response:
 *   {
 *     "success": bool,
 *     "message": string,
 *     "data": mixed|null,
 *     "errors": array<string, array>|null
 *   }
 * This trait keeps those shapes in one place so controllers never repeat
 * the array literal and a future response-format change does not scatter.
 */
trait ApiResponses
{
    /** Render a successful API response. */
    public function success(string $message, $data = null, int $status = 200): \Illuminate\Http\JsonResponse
    {
        $body = [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ];

        return response()->json($body, $status);
    }

    /** Render an error API response (validation/422). */
    public function error(string $message, array $errors = [], int $status = 422): \Illuminate\Http\JsonResponse
    {
        $body = [
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ];

        return response()->json($body, $status);
    }

    /** Render an authentication/authorization error (401/403). */
    public function unauthenticated(string $message, int $status = 401): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => ['token' => [$message]],
        ], $status);
    }

    /** Render a forbidden (role/ability) error. */
    public function forbidden(string $message, int $status = 403): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => ['token' => [$message]],
        ], $status);
    }
}
