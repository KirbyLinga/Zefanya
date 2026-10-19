<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class PageController extends Controller
{
    public function shippingReturns(): View
    {
        return view('Buyer.Pages.shipping-returns');
    }

    public function privacyPolicy(): View
    {
        return view('Buyer.Pages.privacy-policy');
    }

    public function termsOfService(): View
    {
        return view('Buyer.Pages.terms-of-service');
    }

    public function about(): View
    {
        return view('Buyer.Pages.about');
    }

    public function contact(): View
    {
        return view('Buyer.Pages.contact');
    }
}
