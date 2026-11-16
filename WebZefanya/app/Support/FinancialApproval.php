<?php

namespace App\Support;

use App\Enums\AdminRole;
use App\Models\Admin\Admin;

/**
 * Money leaving Zefanya, or a refund decision, needs Super Admin approval.
 * An Admin can still review, prepare, reject and submit evidence; only a
 * Super Admin authorizes a refund or records a payout as sent.
 */
final class FinancialApproval
{
    public static function requireSuperAdmin(Admin $admin): void
    {
        abort_unless($admin->role === AdminRole::SuperAdmin->value, 403, 'Only a Super Admin can approve and record this payment.');
    }
}
