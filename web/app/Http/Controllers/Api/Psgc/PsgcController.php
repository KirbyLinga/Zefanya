<?php

namespace App\Http\Controllers\Api\Psgc;

use App\Http\Controllers\Controller;
use App\Models\Psgc\PsgcBarangay;
use App\Models\Psgc\PsgcCity;
use App\Models\Psgc\PsgcProvince;
use App\Models\Psgc\PsgcRegion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PsgcController extends Controller
{
    /**
     * GET /api/psgc/regions
     * GET /api/v1/psgc/regions
     */
    public function regions(): JsonResponse
    {
        $regions = PsgcRegion::select('code', 'name', 'short_name', 'official_name')
            ->orderBy('code')
            ->get();

        return response()->json([
            'ok' => true,
            'data' => $regions,
        ]);
    }

    /**
     * GET /api/psgc/provinces
     * GET /api/v1/psgc/provinces
     */
    public function provinces(Request $request): JsonResponse
    {
        $query = PsgcProvince::select('code', 'region_code', 'name')->orderBy('name');

        if ($request->filled('region_code')) {
            $query->where('region_code', $request->input('region_code'));
        }

        return response()->json([
            'ok' => true,
            'data' => $query->get(),
        ]);
    }

    /**
     * GET /api/psgc/cities
     * GET /api/v1/psgc/cities
     */
    public function cities(Request $request): JsonResponse
    {
        $query = PsgcCity::select('code', 'region_code', 'province_code', 'name', 'postal_code')->orderBy('name');

        if ($request->filled('province_code')) {
            $query->where('province_code', $request->input('province_code'));
        } elseif ($request->filled('region_code')) {
            $query->where('region_code', $request->input('region_code'));
        }

        return response()->json([
            'ok' => true,
            'data' => $query->get(),
        ]);
    }

    /**
     * GET /api/psgc/barangays
     * GET /api/v1/psgc/barangays
     */
    public function barangays(Request $request): JsonResponse
    {
        $query = PsgcBarangay::select('code', 'city_code', 'name')->orderBy('name');

        if ($request->filled('city_code')) {
            $query->where('city_code', $request->input('city_code'));
        }

        return response()->json([
            'ok' => true,
            'data' => $query->get(),
        ]);
    }
}
