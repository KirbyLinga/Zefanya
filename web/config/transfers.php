<?php

return [
    'overdue_after_hours' => (int) env('TRANSFER_OVERDUE_AFTER_HOURS', 24),
    'overdue_hours' => ['SEA' => 72, 'AIR' => 48],
    'escalate_after_hours' => (int) env('TRANSFER_ESCALATE_AFTER_HOURS', 24),
];
