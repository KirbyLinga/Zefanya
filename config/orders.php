<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Preparation deadline
    |--------------------------------------------------------------------------
    |
    | How long a Seller has to pack and mark an accepted order ready for
    | pickup. The deadline is recorded when the order is accepted. Passing it
    | notifies the Seller once; it never cancels the order automatically, so
    | inventory, vouchers and payments stay untouched until a Seller or Buyer
    | takes an authorized action.
    |
    */
    'preparation_deadline_hours' => (int) env('ORDER_PREPARATION_DEADLINE_HOURS', 48),
];
