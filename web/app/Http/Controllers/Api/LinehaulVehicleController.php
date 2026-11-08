<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Http\Requests\Api\LinehaulVehicleRequest;
use App\Http\Resources\Mobile\LinehaulDocumentResource;
use App\Http\Resources\Mobile\LinehaulVehicleResource;
use App\Services\Logistics\LinehaulProfileService;

class LinehaulVehicleController extends Controller
{
    use ApiResponses;

    public function show(LinehaulVehicleRequest $request)
    {
        return $this->success('Your vehicle records.', new LinehaulVehicleResource($request->user()->fresh()))->header('Cache-Control', 'no-store, private');
    }

    public function store(LinehaulVehicleRequest $request, LinehaulProfileService $service)
    {
        $service->submit($request->user(), $request->validated());

        return $this->show($request);
    }

    public function document(LinehaulVehicleRequest $request, string $document)
    {
        return new LinehaulDocumentResource(LinehaulProfileService::current($request->user())?->documents[$document] ?? null);
    }
}
