<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Logistics\LinehaulVehicleReviewRequest;
use App\Http\Resources\Mobile\LinehaulDocumentResource;
use App\Http\Resources\Mobile\LinehaulVehicleResource;
use App\Models\Logistics\LinehaulDriver;
use App\Services\Logistics\LinehaulProfileService;

class LinehaulVehicleController extends Controller
{
    public function index(LinehaulVehicleReviewRequest $request)
    {
        $drivers = LinehaulDriver::where('logistics_provider_id', $request->user('logistics')->id)->orderBy('name')->paginate(25);
        $records = LinehaulVehicleResource::collection($drivers)->resolve($request);

        return response()->view('Logistics.courier-vehicles', compact('drivers', 'records'))->header('Cache-Control', 'no-store, private');
    }

    public function show(LinehaulVehicleReviewRequest $request, LinehaulDriver $driver)
    {
        $records = (new LinehaulVehicleResource($driver))->resolve($request);

        return response()->view('Logistics.courier-vehicle', compact('driver', 'records'))->header('Cache-Control', 'no-store, private');
    }

    public function review(LinehaulVehicleReviewRequest $request, LinehaulDriver $driver, LinehaulProfileService $service)
    {
        $service->review($request->user('logistics'), $driver, $request->validated());

        return redirect()->route('logistics.couriers.vehicle', $driver)->with('success', 'Courier records reviewed.');
    }

    public function document(LinehaulVehicleReviewRequest $request, LinehaulDriver $driver, string $document)
    {
        return new LinehaulDocumentResource(LinehaulProfileService::current($driver)?->documents[$document] ?? null);
    }
}
