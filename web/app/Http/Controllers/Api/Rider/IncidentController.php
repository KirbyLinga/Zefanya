<?php

namespace App\Http\Controllers\Api\Rider;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Http\Requests\Api\Rider\StoreIncidentApiRequest;
use App\Models\Shared\SellerOrder;
use App\Services\Logistics\ParcelIncidentService;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/rider/jobs/{sellerOrder}/incidents — report a lost or damaged
 * parcel from the mobile app.
 *
 * Same ParcelIncidentService::report call as the session route
 * (Rider\ParcelIncidentController): the service locks the parcel, checks the
 * rider owns it, refuses a second open incident and blocks parcel operations
 * until Admin resolves it. Nothing is re-checked or relaxed here.
 */
class IncidentController extends Controller
{
    use ApiResponses;

    public function store(StoreIncidentApiRequest $request, SellerOrder $sellerOrder, ParcelIncidentService $incidents): JsonResponse
    {
        $incident = $incidents->report($sellerOrder, $request->user(), $request->validated());

        return $this->success('Incident reported. Parcel operations are blocked pending Admin resolution.', [
            'incident_id' => $incident->id,
            'parcel_id' => $sellerOrder->id,
            'type' => $incident->type,
            'status' => $incident->status,
            'reason' => $incident->reason,
            'description' => $incident->description,
            'has_photo' => $incident->photo_path !== null,
            'created_at' => $incident->created_at?->toIso8601String(),
        ], 201);
    }
}
