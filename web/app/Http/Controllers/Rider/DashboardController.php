<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Services\RiderOperationsService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, RiderOperationsService $operations): View
    {
        $rider = $request->user('rider');
        $rider->loadMissing(['provider', 'station']);

        return view('Rider.Dashboard.index', [
            'rider' => $rider,
            'stats' => $operations->dashboard($rider),
        ]);
    }
}
