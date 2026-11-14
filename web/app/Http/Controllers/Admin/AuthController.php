<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\ClearsOtherGuards;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    use ClearsOtherGuards;

    public function showLogin()
    {
        if (Auth::guard('admin')->check()) {
            return redirect()->route('admin.dashboard');
        }

        return view('Admin.Auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::guard('admin')->attempt($credentials, $request->boolean('remember'))) {
            $request->session()->forget('admin.2fa');

            // One account per browser: end every other role's session.
            $this->clearOtherGuards('admin');

            $request->session()->regenerate();

            return redirect()->intended(route('admin.dashboard'));
        }

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Email or password is incorrect.',
                'errors' => ['email' => ['Email or password is incorrect.']],
            ], 422);
        }

        return back()
            ->withErrors(['email' => 'Email or password is incorrect.'])
            ->onlyInput('email', 'remember');
    }

    public function logout(Request $request)
    {
        $this->clearAllGuards();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
