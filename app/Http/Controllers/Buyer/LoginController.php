<?php

namespace App\Http\Controllers\Buyer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LoginController extends Controller
{
    /**
     * GET /login — only needed if login is a standalone page rather than
     * a modal (login-modal.blade.php may make this unused; kept in case
     * a no-JS fallback page is wanted, same pattern as the OTP pages).
     */
    public function show()
    {
        return view('Buyer.Auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $buyer = \App\Models\Buyer::where('email', $credentials['email'])->first();

        // Distinguish "wrong password" from "right password, wrong status"
        // so the person knows what's actually blocking them.
        if (! $buyer || ! Auth::guard('buyer')->attempt($credentials)) {
            return $this->fail($request, 'email', 'Those credentials don\'t match our records.');
        }

        if ($buyer->status !== 'approved') {
            Auth::guard('buyer')->logout();

            $message = match ($buyer->status) {
                'pending_verification' => 'Please verify your email before logging in. Check your inbox for the code.',
                'pending_approval' => 'Your registration is still awaiting admin approval.',
                'rejected' => 'Your registration was not approved.'
                    .($buyer->rejection_reason ? " Reason: {$buyer->rejection_reason}" : ''),
                default => 'Your account isn\'t active yet.',
            };

            return $this->fail($request, 'email', $message);
        }

        $request->session()->regenerate();

        if ($request->wantsJson()) {
            return response()->json(['redirect' => route('buyer.home')]);
        }

        return redirect()->intended(route('buyer.home'));
    }

    public function logout(Request $request)
    {
        Auth::guard('buyer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->wantsJson()) {
            return response()->json(['redirect' => route('home')]);
        }

        return redirect()->route('home');
    }

    private function fail(Request $request, string $field, string $message)
    {
        if ($request->wantsJson()) {
            return response()->json(['message' => $message], 422);
        }

        return back()->withErrors([$field => $message])->onlyInput('email');
    }
}
