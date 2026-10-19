<?php

namespace App\Http\Middleware\Rider;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureRiderApproved
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user('rider')?->isApproved()) {
            Auth::guard('rider')->logout();
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Your Rider application must be verified and approved by Logistics.'], 403);
            }

            return redirect()->route('home', ['login' => 1])->with('auth.status_message', 'Your Rider application must be verified and approved by Logistics.');
        }

        return $next($request);
    }
}
