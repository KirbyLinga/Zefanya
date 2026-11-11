<?php

/*
| Hub-to-hub transfer batches (manifests).
*/

return [
    // New batches and handoffs must have an assigned Courier before dispatch.
    // Transfers created before this rule are never affected.
    'require_transport' => (bool) env('TRANSFER_TRANSPORT_REQUIRED', true),

    // A dispatched transfer not fully received within this many hours is shown
    // as overdue and its sending company is told once. This is the ROAD limit
    // and the fallback for any mode without its own. It changes no custody or
    // route state, and overdue never means lost.
    'overdue_after_hours' => (int) env('TRANSFER_OVERDUE_AFTER_HOURS', 24),

    // Longer limits for connections that take longer than a road trip. Set
    // them to what your routes really take.
    'overdue_hours' => [
        'SEA' => (int) env('TRANSFER_OVERDUE_HOURS_SEA', 72),
        'AIR' => (int) env('TRANSFER_OVERDUE_HOURS_AIR', 48),
    ],

    // Further hours an overdue transfer may stay unresolved before Admin is
    // told once. Admin hears nothing earlier.
    'escalate_after_hours' => (int) env('TRANSFER_ESCALATE_AFTER_HOURS', 24),
];
