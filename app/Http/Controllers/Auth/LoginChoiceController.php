<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Unified login entry point — GET /login.
 *
 * The application has exactly one login screen. It serves both buyer and
 * seller accounts: the form posts to POST /login (UnifiedLoginController),
 * which determines the role from the credentials and redirects to the
 * matching area:
 *
 *   seller → /seller/dashboard
 *   buyer  → /buyer
 *
 * There is no standalone /seller/login screen any more; that URL is a
 * pass-through kept only for old bookmarks.
 */
class LoginChoiceController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        // Already signed in? Skip the form entirely and send the user to the
        // area that matches the active session, so /login never appears in
        // the address bar for an authenticated user.
        $seller = Auth::guard('seller')->user();

        if ($seller) {
            if ($seller->status === 'approved') {
                return redirect()->route('seller.dashboard');
            }

            // Stale session for a seller that is no longer approved — clear
            // it so the login form starts from a clean slate.
            Auth::guard('seller')->logout();
        }

        $buyer = Auth::guard('buyer')->user();

        if ($buyer) {
            if ($buyer->status === 'approved') {
                return redirect()->route('buyer.home');
            }

            Auth::guard('buyer')->logout();
        }

        return view('Auth.login-choice');
    }
}
