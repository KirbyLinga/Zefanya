<?php

namespace App\Http\Controllers\Api\Buyer;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Revoke only the current bearer token, preserving independent web sessions. */
class LogoutController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // api.role guarantees a persisted Sanctum personal access token.
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }
}
