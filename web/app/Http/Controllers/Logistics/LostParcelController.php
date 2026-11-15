<?php

namespace App\Http\Controllers\Logistics;

use App\Http\Controllers\Controller;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\ParcelLossCase;
use App\Services\Logistics\ParcelLossService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LostParcelController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(ParcelLossService::available(), 503);
        $provider = $request->user('logistics');

        return view('Logistics.lost-parcels', [
            'cases' => ParcelLossCase::query()->where('logistics_provider_id', $provider->id)->with('sellerOrder')->latest('id')->paginate(25),
            'hubs' => LogisticsHub::query()->where('logistics_provider_id', $provider->id)->operational()->orderBy('name')->get(),
        ]);
    }

    public function verifyScan(Request $request, ParcelLossCase $case, ParcelLossService $loss): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['tracking_number' => 'required|string|max:64', 'hub' => 'required|integer|min:1']);
        $token = $loss->verifyFind($case, $request->user('logistics'), (int) $data['hub'], $data['tracking_number']);

        if ($request->expectsJson()) {
            return response()->json(['verification_token' => $token]);
        }

        return back()->with('loss_scan', ['id' => $case->id, 'hub' => $data['hub'], 'token' => $token]);
    }

    public function recover(Request $request, ParcelLossCase $case, ParcelLossService $loss): RedirectResponse
    {
        $data = $request->validate(['verification_token' => 'required|string|max:2048', 'hub' => 'required|integer|min:1']);
        $loss->recordFind($case, $request->user('logistics'), (int) $data['hub'], $data['verification_token']);

        return back()->with('success', 'Found parcel recorded at the selected hub.');
    }
}
