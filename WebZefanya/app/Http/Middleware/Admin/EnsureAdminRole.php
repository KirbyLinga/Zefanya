<?php

namespace App\Http\Middleware\Admin;

use App\Enums\AdminRole;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    /**
     * Usage: Route::middleware('admin.role:admin,moderator')->group(...)
     *
     * Accepts one or more allowed roles. `super_admin` always passes. Roles
     * come from the AdminRole enum (the single source of truth).
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $admin = Auth::guard('admin')->user();

        if (! $admin) {
            abort(403, 'You do not have permission to access this area.');
        }

        if ($admin->role === AdminRole::SuperAdmin->value || in_array($admin->role, $roles, true)) {
            return $next($request);
        }

        abort(403, 'You do not have permission to access this area.');
    }
}
