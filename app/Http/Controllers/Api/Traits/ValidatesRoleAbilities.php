<?php

namespace App\Http\Controllers\Api\Traits;

/** Trait used by API login controllers to centralise the account-status gate.
 *
 * The role-ability check (tokenCan) is performed by the api.role middleware;
 * this trait only knows the account-level status rules shared by the buyer
 * and rider logins: approved, email-verified, and not in a terminal
 * rejected/suspended/deactivated state.
 */
trait ValidatesRoleAbilities
{
    /** Return true when the account may log in.
     *
     * The base gate is "approved or active status, email verified, and not in
     * a terminal rejected/suspended/deactivated state". Roles with an extra
     * operational requirement (riders need an assigned, operational station
     * hub and a linked Courier) override this via accountIsOperational().
     */
    public function accountIsLoginable(object $account): bool
    {
        if (! $account->isEmailVerified()) {
            return false;
        }

        $status = $account->status ?? '';

        if (! in_array($status, ['approved', 'active'], true)) {
            return false;
        }

        if (in_array($status, ['rejected', 'suspended', 'deactivated'], true)) {
            return false;
        }

        return $this->accountIsOperational($account);
    }

    /** Roles-specific operational/eligibility gate.
     *
     * Buyers have no additional operational requirement. Riders additionally
     * require an assigned, operational station hub and a linked Courier
     * record (Rider::isOperational()).
     */
    protected function accountIsOperational(object $account): bool
    {
        return true;
    }
}
