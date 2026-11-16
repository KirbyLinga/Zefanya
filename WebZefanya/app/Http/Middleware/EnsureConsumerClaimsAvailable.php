<?php

namespace App\Http\Middleware;

use App\Services\Buyer\ConsumerClaimAvailability;
use Closure;
use Illuminate\Http\Request;

class EnsureConsumerClaimsAvailable
{
    public function handle(Request $request, Closure $next)
    {
        app(ConsumerClaimAvailability::class)->requireAvailable();

        return $next($request);
    }
}
