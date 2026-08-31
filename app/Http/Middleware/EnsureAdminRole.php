<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    /**
     * Usage: Route::middleware('admin.role:super_admin')->group(...)
     */
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin || $admin->role !== $role) {
            abort(403, 'You do not have permission to access this area.');
        }

        return $next($request);
    }
}