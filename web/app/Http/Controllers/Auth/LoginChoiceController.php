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
 * The application has exactly one login surface (the modal on every
 * Layout.footer page, posting to POST /login). This standalone page is a
 * no-JS fallback that mirrors the modal form so deep-links and
 * non-JS contexts always have a working login endpoint.
 *
 * If the visitor is already authenticated under any guard they are
 * sent to their matching dashboard area instead.
 */
class LoginChoiceController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        // Already signed in as seller?
        if (Auth::guard('seller')->check() && Auth::guard('seller')->user()->status === 'approved') {
            return redirect()->route('seller.dashboard');
        }
        Auth::guard('seller')->logout();

        // Already signed in as logistics provider?
        if (Auth::guard('logistics')->check() && Auth::guard('logistics')->user()->isApproved()) {
            return redirect()->route('logistics.dashboard');
        }
        Auth::guard('logistics')->logout();

        // Already signed in as buyer?
        if (Auth::guard('buyer')->check() && Auth::guard('buyer')->user()->status === 'approved') {
            return redirect()->route('buyer.home');
        }
        Auth::guard('buyer')->logout();

        // No-JS fallback: the login modal lives on every Layouts.footer page.
        // Render a standalone page so deep-links and non-JS contexts still work.
        return view('Auth.login-choice');
    }
}
