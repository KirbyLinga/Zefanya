<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\LogisticsProviderStatus;
use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsProvider;
use App\Notifications\Logistics\LogisticsRegistrationDecision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * GET /api/v1/admin/logistics/pending
 * POST /api/v1/admin/logistics/{logisticsProvider}/approve
 * POST /api/v1/admin/logistics/{logisticsProvider}/reject
 *
 * Minimal admin rider-approval surface for the mobile marketplace:
 * an admin lists pending courier applications and approves or rejects them.
 */
class AdminLogisticsController extends Controller
{
    /** List pending courier (logistics) applications. */
    public function pending(Request $request): JsonResponse
    {
        $admin = auth('admin')->user();
        abort_unless($admin !== null, 403, 'Admin authentication required.');

        $pending = LogisticsProvider::where('status', LogisticsProviderStatus::PendingApproval->value)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'pending' => $pending->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->fullName(),
                'email' => $l->email,
                'status' => $l->status->value,
                'created_at' => $l->created_at,
            ]),
        ]);
    }

    /** Approve a pending courier application. */
    public function approve(Request $request, LogisticsProvider $logisticsProvider): JsonResponse
    {
        $admin = auth('admin')->user();
        abort_unless($admin !== null, 403, 'Admin authentication required.');

        $logisticsProvider->update([
            'status' => LogisticsProviderStatus::Approved->value,
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        $logisticsProvider->notify(new LogisticsRegistrationDecision('approved'));

        return response()->json([
            'ok' => true,
            'status' => $logisticsProvider->status->value,
        ]);
    }

    /** Reject a pending courier application. */
    public function reject(Request $request, LogisticsProvider $logisticsProvider): JsonResponse
    {
        $admin = auth('admin')->user();
        abort_unless($admin !== null, 403, 'Admin authentication required.');

        $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        $logisticsProvider->update([
            'status' => LogisticsProviderStatus::Rejected->value,
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'rejection_reason' => $request->rejection_reason,
        ]);

        $logisticsProvider->notify(new LogisticsRegistrationDecision('rejected', $request->rejection_reason));

        return response()->json([
            'ok' => true,
            'status' => $logisticsProvider->status->value,
        ]);
    }
}