<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $rider = Auth::guard('rider')->user()->load('provider');

        return view('Rider.dashboard', ['rider' => $rider]);
    }
}
