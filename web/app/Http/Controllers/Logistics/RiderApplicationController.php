<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Rider\Rider;
use App\Services\Rider\RiderRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RiderApplicationController extends Controller
{
    public function index(Request $request, RiderRegistrationService $service): JsonResponse
    {
        $provider = $request->user('logistics');
        $service->authorizeProvider($provider);
        $riders = Rider::where('logistics_provider_id', $provider->id)->where('status', 'pending')
            ->orderBy('id')->paginate(25, ['id', 'first_name', 'last_name', 'email', 'contact_no', 'birthday', 'license_no', 'license_expiry', 'vehicle_type', 'plate_number', 'address_mode', 'province_name', 'municipality_name', 'barangay_name', 'street', 'house_number', 'address_detail', 'postal_code', 'status', 'station_hub_id', 'email_verified_at', 'created_at']);

        return response()->json($riders);
    }

    public function document(Request $request, Rider $rider, string $document, RiderRegistrationService $service): StreamedResponse
    {
        $service->authorizeProvider($request->user('logistics'));
        abort_unless((int) $rider->logistics_provider_id === (int) $request->user('logistics')->id, 404);
        $path = match ($document) {
            'drivers_license' => $rider->drivers_license_path,
            'or_cr' => $rider->or_cr_path,
            default => abort(404),
        };
        $disk = Storage::disk('local');
        abort_unless($path && $disk->exists($path), 404);

        return $disk->download($path, basename($path), ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    public function approve(Request $request, Rider $rider, RiderRegistrationService $service): JsonResponse
    {
        $stationHubId = $request->integer('station_hub_id') ?: null;
        $row = $service->decide($rider, $request->user('logistics'), 'approved', null, $stationHubId);

        return response()->json([
            'message' => 'Rider application approved.',
            'data' => ['rider_id' => $row->id, 'status' => $row->status, 'station_hub_id' => $row->station_hub_id],
        ]);
    }

    public function reject(Request $request, Rider $rider, RiderRegistrationService $service): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:300']]);
        $row = $service->decide($rider, $request->user('logistics'), 'rejected', $data['reason']);

        return response()->json(['message' => 'Rider application rejected.', 'data' => ['rider_id' => $row->id, 'status' => $row->status]]);
    }
}
