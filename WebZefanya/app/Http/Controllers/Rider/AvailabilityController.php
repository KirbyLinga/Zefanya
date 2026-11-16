<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Services\Rider\RiderAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The Rider's own online/offline switch. Offline Riders get no NEW jobs,
 * automatic or manual. Jobs not started yet are flagged for Logistics to
 * reassign; parcels already in hand stay with the Rider to finish.
 */
class AvailabilityController extends Controller
{
    public function update(Request $request, RiderAvailabilityService $availability): RedirectResponse
    {
        $data = $request->validate(['online' => ['required', 'boolean']]);
        $online = (bool) $data['online'];
        $result = $availability->setOnline($request->user('rider'), $online);

        if ($online) {
            return back()->with('success', 'You are online. You can receive new pickup and delivery jobs.');
        }

        return back()->with('success', $result['flagged'] > 0
            ? "You are offline. {$result['flagged']} job(s) you had not started were sent to Logistics for reassignment; parcels already in your hands stay with you."
            : 'You are offline. You will not receive new jobs; finish the ones you already have.');
    }
}
