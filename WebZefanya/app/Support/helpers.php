<?php

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

if (! function_exists('manila')) {
    /**
     * Format a stored (UTC) date/time for display in the business timezone.
     * Display only: never use the result for comparisons or queries. The input
     * is copied, so the caller's Carbon instance keeps its timezone.
     */
    function manila(CarbonInterface|DateTimeInterface|string|int|null $date, string $format = 'M d, Y g:i A', string $fallback = ''): string
    {
        if ($date === null || $date === '') {
            return $fallback;
        }

        try {
            $moment = $date instanceof CarbonInterface
                ? $date->copy()
                : (is_int($date) ? Carbon::createFromTimestamp($date) : Carbon::parse($date, config('app.timezone')));
        } catch (Throwable) {
            return $fallback;
        }

        return $moment->timezone(config('app.schedule_timezone', 'Asia/Manila'))->format($format);
    }
}
