<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class CartController extends Controller
{
    /**
     * POST /buyer/cart/add — add a product to the buyer's cart.
     * Returns 401 JSON for unauthenticated requests so the frontend
     * can open the login modal instead of following a redirect.
     */
    public function add(Request $request)
    {
        if (! auth('buyer')->check()) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        // TODO: actual cart logic once Cart is built
        return response()->json(['success' => true, 'cart_count' => 0]);
    }
}
