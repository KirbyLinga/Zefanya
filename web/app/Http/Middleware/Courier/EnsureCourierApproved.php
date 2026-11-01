<?php

namespace App\Http\Middleware\Courier;

use App\Enums\LogisticsProviderStatus;
use App\Models\Logistics\LogisticsProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prevent pending and rejected riders from reaching any courier API route
 * even when they hold a valid Sanctum token (e.g. a token issued before
 * their application was rejected, or issued during the brief window between
 * email verification and admin review).
 *
 * Applied to every courier-scoped API route. The login controller calls the
 * same status-gate logic before issuing a token, but this middleware is the
 * layer that matters: tokens are long-lived and a status change after issuance
 * must propagate immediately.
 */
class EnsureCourierApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof LogisticsProvider) {
            return response()->json([
                'ok' => false,
                'message' => 'This route is for couriers only.',
            ], 403);
        }

        if ($user->status === LogisticsProviderStatus::Rejected) {
            return response()->json([
                'ok' => false,
                'code' => 'ACCOUNT_REJECTED',
                'message' => $user->rejection_reason ?: 'Your rider application was not approved.',
            ], 403);
        }

        if ($user->status !== LogisticsProviderStatus::Approved) {
            return response()->json([
                'ok' => false,
                'code' => 'PENDING_APPROVAL',
                'message' => 'Your rider account is pending approval.',
            ], 403);
        }

        return $next($request);
    }
}
