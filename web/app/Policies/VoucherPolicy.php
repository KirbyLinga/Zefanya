<?php

namespace App\Policies;

use App\Enums\AdminRole;
use App\Enums\VoucherScope;
use App\Models\Admin\Admin;
use App\Models\Seller\Seller;
use App\Models\Shared\Voucher;

class VoucherPolicy
{
    /** A voucher can only be changed by the Seller whose shop it belongs to. */
    public function manage(Seller $seller, Voucher $voucher): bool
    {
        return $voucher->seller_id === $seller->getKey();
    }

    /** Platform and shipping vouchers are marketplace-funded, so only an admin may change them. */
    public function administer(Admin $admin, Voucher $voucher): bool
    {
        return in_array($admin->role, [AdminRole::SuperAdmin->value, AdminRole::Admin->value], true)
            && in_array($voucher->effectiveScope(), [VoucherScope::Platform, VoucherScope::Shipping], true);
    }
}
