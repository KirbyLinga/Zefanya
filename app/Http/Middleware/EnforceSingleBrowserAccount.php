<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * "One account per browser" safety net.
 *
 * Each guard keeps its own login key inside the shared session, so a browser
 * can end up holding more than one role (legacy session, a missed logout, a
 * code path that forgot to clear the others). Whatever the cause, only ONE role
 * may stay signed in: the one that owns the area being visited, otherwise the
 * most recently signed-in role.
 *
 * This is a net, not the primary rule — every login path already calls
 * ClearsOtherGuards::clearOtherGuards().
 */
class EnforceSingleBrowserAccount
{
    /**
     * Guards that participate in the single-session rule, most privileged first.
     *
     * @var list<string>
     */
    private const GUARDS = ['admin', 'seller', 'logistics', 'buyer'];

    public function handle(Request $request, Closure $next): Response
    {
        $active = array_values(array_filter(
            self::GUARDS,
            fn (string $guard): bool => Auth::guard($guard)->check(),
        ));

        if (count($active) > 1) {
            $keep = $this->preferredGuard($request, $active);

            foreach ($active as $guard) {
                if ($guard !== $keep) {
                    Auth::guard($guard)->logout();
                    $request->session()->forget('password_hash_'.$guard);
                }
            }
        }

        return $next($request);
    }

    /**
     * @param  list<string>  $active
     */
    private function preferredGuard(Request $request, array $active): string
    {
        $area = $this->areaFor($request);

        if ($area !== null && in_array($area, $active, true)) {
            return $area;
        }

        $last = $request->session()->get('auth.last_guard');

        if (is_string($last) && in_array($last, $active, true)) {
            return $last;
        }

        return $active[0];
    }

    private function areaFor(Request $request): ?string
    {
        if ($request->is('admin', 'admin/*')) {
            return 'admin';
        }

        if ($request->is('seller', 'seller/*')) {
            return 'seller';
        }

        if ($request->is('logistics', 'logistics/*')) {
            return 'logistics';
        }

        if ($request->is('buyer', 'buyer/*')) {
            return 'buyer';
        }

        return null;
    }
}
