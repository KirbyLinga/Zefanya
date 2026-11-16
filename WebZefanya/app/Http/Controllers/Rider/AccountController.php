<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function show(Request $request): View
    {
        $rider = $request->user('rider');
        $rider->loadMissing(['provider', 'station']);

        return view('Rider.Account.index', ['rider' => $rider]);
    }
}
