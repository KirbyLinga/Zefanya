<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\Traits\ApiResponses;
use App\Http\Requests\Api\LinehaulLoginRequest;
use App\Http\Requests\Api\LinehaulRequest;
use App\Http\Resources\Mobile\LinehaulTripResource;
use App\Models\Logistics\LinehaulAssignment;
use App\Models\Logistics\LinehaulDriver;
use App\Models\Logistics\Manifest;
use App\Services\Logistics\LinehaulService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Hash;

class LinehaulController extends Controller
{
    use ApiResponses;

    public function login(LinehaulLoginRequest $request): JsonResponse
    {
        $data = $request->validated();
        $driver = LinehaulDriver::query()->where('email', strtolower(trim($data['email'])))->first();
        if ($driver === null || ! Hash::check($data['password'], $driver->password)) {
            throw new AuthenticationException;
        }
        abort_unless($driver->isOperational(), 403, 'Your Courier account or logistics company is not active.');
        $token = $driver->createToken('courier-app', ['linehaul'], now()->addMinutes(60));

        return $this->success('Login successful.', new JsonResource([
            'token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at->toIso8601String(),
        ]));
    }

    public function profile(LinehaulRequest $request): JsonResponse
    {
        return $this->success('Courier profile.', new \App\Http\Resources\Mobile\LinehaulProfileResource($request->user()))
            ->header('Cache-Control', 'no-store, private');
    }

    public function logout(LinehaulRequest $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success('Logged out.', new JsonResource([]));
    }

    public function trips(LinehaulRequest $request): JsonResponse
    {
        $trips = LinehaulAssignment::query()->currentOffer()->where('linehaul_driver_id', $request->user()->id)
            ->whereHas('manifest', fn ($query) => $query->where('logistics_provider_id', $request->user()->logistics_provider_id))
            ->with(['driver', 'manifest.originHub', 'manifest.destinationHub', 'manifest.items.sellerOrder:id,parcel_weight_grams'])
            ->when(\App\Services\Logistics\LinehaulDelayService::installed(), fn ($query) => $query->with('latestDelay'))
            ->when(\App\Services\Logistics\LinehaulTransferService::installed(), fn ($query) => $query->with('latestTransfer'))
            ->latest('id')->limit(50)->get();

        $rows = $trips->map(fn ($trip) => (new LinehaulTripResource($trip))->resolve($request));
        if (\App\Services\Logistics\LinehaulDeclineService::installed()) {
            $declines = \App\Models\Logistics\LinehaulTripDecline::where('linehaul_driver_id', $request->user()->id)
                ->where('logistics_provider_id', $request->user()->logistics_provider_id)->latest('id')->limit(50)->get();
            $rows = $rows->concat($declines->map(fn ($decline) => (new \App\Http\Resources\Mobile\LinehaulDeclineResource($decline))->resolve($request)));
        }

        if (\App\Services\Logistics\LinehaulTransferService::installed()) {
            $transfers = \App\Models\Logistics\LinehaulTransfer::where('from_driver_id', $request->user()->id)
                ->where('logistics_provider_id', $request->user()->logistics_provider_id)->latest('id')->limit(50)->get();
            $rows = $rows->concat($transfers->map(fn ($transfer) => (new \App\Http\Resources\Mobile\LinehaulTransferResource($transfer))->resolve($request)));
        }

        return $this->success('Your trips.', new JsonResource($rows->values()));
    }

    public function decline(\App\Http\Requests\Api\LinehaulDeclineRequest $request, Manifest $manifest, \App\Services\Logistics\LinehaulDeclineService $service): JsonResponse
    {
        $decline = $service->decline($request->user(), $manifest, $request->validated(), $request);

        return $this->success('Trip declined. Logistics can assign another Courier.', new \App\Http\Resources\Mobile\LinehaulDeclineResource($decline));
    }

    public function earnings(\App\Http\Requests\Api\LinehaulEarningsRequest $request, \App\Services\Logistics\LinehaulEarningsService $service): JsonResponse
    {
        return $this->success('Completed handover earnings.', new \App\Http\Resources\Mobile\LinehaulEarningsResource(
            $service->report($request->user(), $request->validated())))->header('Cache-Control', 'no-store, private');
    }

    public function history(\App\Http\Requests\Api\LinehaulHistoryRequest $request, \App\Services\Logistics\LinehaulHistoryService $service): JsonResponse
    {
        return $this->success('Your dated trip history.', new \App\Http\Resources\Mobile\LinehaulHistoryResource(
            $service->report($request->user(), $request->validated(), $request)))->header('Cache-Control', 'no-store, private');
    }

    public function duty(\App\Http\Requests\Api\LinehaulDutyRequest $request): JsonResponse
    {
        return $this->success('Your duty status.', new \App\Http\Resources\Mobile\LinehaulDutyResource($request->user()->fresh()))->header('Cache-Control', 'no-store, private');
    }

    public function changePush(\App\Http\Requests\Api\LinehaulPushRequest $request, \App\Services\Logistics\LinehaulPushService $service): JsonResponse
    {
        $enabled = $service->change($request->user(), $request->boolean('enabled'), $request->validated('device_token'));

        return $this->success('Courier notification preference saved.', new \App\Http\Resources\Mobile\LinehaulPushResource($enabled))
            ->header('Cache-Control', 'no-store, private');
    }

    public function reportDelay(\App\Http\Requests\Api\LinehaulDelayRequest $request, Manifest $manifest, \App\Services\Logistics\LinehaulDelayService $service): JsonResponse
    {
        $assignment = $service->report($request->user(), $manifest, $request->validated(), $request);
        $assignment->load(['manifest.originHub', 'manifest.destinationHub', 'manifest.items.sellerOrder:id,parcel_weight_grams', 'latestDelay']);

        return $this->success('Delay reported to Logistics.', new LinehaulTripResource($assignment));
    }

    public function reportBreakdown(\App\Http\Requests\Api\LinehaulBreakdownRequest $request, Manifest $manifest, \App\Services\Logistics\LinehaulBreakdownService $service): JsonResponse
    {
        return $this->success('Breakdown reported to Logistics.', new \App\Http\Resources\Mobile\LinehaulBreakdownResource(
            $service->report($request->user(), $manifest, $request->validated(), $request)))->header('Cache-Control', 'no-store, private');
    }

    public function changeDuty(\App\Http\Requests\Api\LinehaulDutyRequest $request, string $dutyAction, \App\Services\Logistics\LinehaulDutyService $service): JsonResponse
    {
        $driver = $service->change($request->user(), $dutyAction, $request->validated());

        return $this->success('Duty status updated.', new \App\Http\Resources\Mobile\LinehaulDutyResource($driver))->header('Cache-Control', 'no-store, private');
    }

    public function act(LinehaulRequest $request, Manifest $manifest, string $action, LinehaulService $service): JsonResponse
    {
        $assignment = $service->act($request->user(), $manifest, $action, $request->validated(), $request);
        $assignment->load(['manifest.originHub', 'manifest.destinationHub', 'manifest.items.sellerOrder:id,parcel_weight_grams']);
        if (\App\Services\Logistics\LinehaulDelayService::installed()) {
            $assignment->load('latestDelay');
        }

        return $this->success('Trip updated.', new LinehaulTripResource($assignment));
    }
}
