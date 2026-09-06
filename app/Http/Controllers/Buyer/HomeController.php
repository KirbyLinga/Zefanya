<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;

class HomeController extends Controller
{
    public function index()
    {
        return view('Buyer.Home.index');
    }
}
