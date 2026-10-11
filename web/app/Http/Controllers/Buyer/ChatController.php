<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;

/**
 * Stop-gap controller: the chat feature is not built yet, but the routes
 * (buyer.chat.index / buyer.chat.show) exist and are linked from the buyer
 * footer. Without these methods any direct URL access was a 500
 * ("Call to undefined method"). Redirect back to the storefront with a
 * friendly flash message instead.
 *
 * When real chat lands, replace both methods with the actual implementations
 * (and wire up resources/views/Buyer/Chat/*, which are stubs right now).
 */
class ChatController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()
            ->route('buyer.home')
            ->with('info', 'Chat is coming soon');
    }

    public function show(string $id): RedirectResponse
    {
        return redirect()
            ->route('buyer.home')
            ->with('info', 'Chat is coming soon');
    }
}
