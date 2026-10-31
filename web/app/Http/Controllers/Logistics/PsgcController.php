<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Services\Shared\PhilippineLocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PsgcController extends Controller
{
    public function __construct(
        private readonly PhilippineLocationService $locationService
    ) {}

    /**
     * Get cities/municipalities for a province.
     *
     * @return JsonResponse<list<array{code: string, name: string}>>
     */
    public function municipalities(Request $request): JsonResponse
    {
        $provider = $request->user('logistics');
        $provinceCode = $request->query('province_code');
        $regionCode = $request->query('region_code');

        // When the provider province is verified, only that province's cities are returned.
        if ($provider && $provider->hasVerifiedProvince()) {
            if (filled($provinceCode) && (string) $provinceCode !== (string) $provider->province_code) {
                return response()->json(['message' => 'Municipalities are limited to your verified province.'], 403);
            }
            $provinceCode = (string) $provider->province_code;
        }

        if (blank($provinceCode)) {
            return response()->json(['message' => 'province_code is required'], 422);
        }

        try {
            $municipalities = $this->locationService->getCitiesMunicipalities($provinceCode, $regionCode);

            return response()->json($municipalities);
        } catch (\Throwable $e) {
            \Log::error('PSGC municipalities lookup failed: '.$e->getMessage());

            return response()->json(['message' => 'Could not load municipalities. Please try again.'], 503);
        }
    }

    /**
     * Get barangays for a city/municipality.
     *
     * @return JsonResponse<list<array{code: string, name: string}>>
     */
    public function barangays(Request $request): JsonResponse
    {
        $cityCode = $request->query('city_municipality_code');

        if (blank($cityCode)) {
            return response()->json(['message' => 'city_municipality_code is required'], 422);
        }

        try {
            $barangays = $this->locationService->getBarangays($cityCode);

            return response()->json($barangays);
        } catch (\Throwable $e) {
            \Log::error('PSGC barangays lookup failed: '.$e->getMessage());

            return response()->json(['message' => 'Could not load barangays. Please try again.'], 503);
        }
    }

    /**
     * Validate a full location hierarchy.
     */
    public function validate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'region_code' => ['required', 'string', 'max:20'],
            'province_code' => ['required', 'string', 'max:20'],
            'city_municipality_code' => ['required', 'string', 'max:20'],
            'barangay_code' => ['required', 'string', 'max:20'],
            'region_name' => ['nullable', 'string', 'max:120'],
            'province_name' => ['nullable', 'string', 'max:120'],
            'municipality_name' => ['nullable', 'string', 'max:120'],
            'barangay_name' => ['nullable', 'string', 'max:120'],
        ]);

        try {
            $resolved = $this->locationService->validateAndResolve($data);

            return response()->json([
                'valid' => true,
                'resolved' => $resolved,
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'valid' => false,
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            \Log::error('PSGC validation failed: '.$e->getMessage());

            return response()->json([
                'valid' => false,
                'message' => 'Location validation service is currently unavailable.',
            ], 503);
        }
    }
}
