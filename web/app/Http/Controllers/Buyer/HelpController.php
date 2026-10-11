<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class HelpController extends Controller
{
    /**
     * GET /buyer/help — Display help/support page
     */
    public function index(): View
    {
        return view('Buyer.Help.index');
    }
}
