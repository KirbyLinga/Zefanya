<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class PageController extends Controller
{
    public function about(): View
    {
        return view('Buyer.Pages.about');
    }

    public function contact(): View
    {
        return view('Buyer.Pages.contact');
    }
}
