<?php

namespace App\Http\Middleware\Rider;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureRiderActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $rider = Auth::guard('rider')->user();
        if (! $rider) {
            return redirect()->route('home', ['login' => 1]);
        }
        if ($rider->status !== 'active') {
            Auth::guard('rider')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('home', ['login' => 1])->with('auth.status_message', 'Your rider account is not active yet.');
        }

        return $next($request);
    }
}
