<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Http\Requests\ParcelIncident\StoreIncidentRequest;
use App\Models\SellerOrder;
use App\Services\ParcelIncidentService;
use Illuminate\Http\JsonResponse;

class ParcelIncidentController extends Controller
{
    public function __construct(
        private readonly ParcelIncidentService $incident = new ParcelIncidentService,
    ) {}

    public function store(StoreIncidentRequest $request, SellerOrder $sellerOrder): JsonResponse
    {
        $incident = $this->incident->report(
            $sellerOrder, $request->user('rider'), $request->validated(),
        );

        return response()->json([
            'message' => 'Incident reported. Parcel operations are blocked pending Admin resolution.',
            'data' => [
                'incident_id' => $incident->id,
                'parcel_id' => $sellerOrder->id,
                'type' => $incident->type,
                'status' => $incident->status,
                'reason' => $incident->reason,
                'description' => $incident->description,
                'has_photo' => $incident->photo_path !== null,
                'happened_at' => $incident->happened_at,
                'created_at' => $incident->created_at?->toIso8601String(),
            ],
        ], 201);
    }
}
