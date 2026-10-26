<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Services\RiderOperationsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeliveryHistoryController extends Controller
{
    public function index(Request $request, RiderOperationsService $operations): View
    {
        $rider = $request->user('rider');

        return view('Rider.History.index', [
            'rider' => $rider,
            'shipments' => $operations->deliveryHistory($rider),
        ]);
    }
}
