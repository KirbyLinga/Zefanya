<?php

namespace App\Models\Concerns;

/**
 * Archiving for self-registration rows (buyers, sellers, logistics providers).
 *
 * A rejected applicant — or an abandoned unverified one — must never be
 * hard-deleted: the decision trail (`rejection_reason`, `approved_by`, …) is
 * kept. Re-registration instead ARCHIVES the old row: `archived_at` is stamped
 * and the unique `email` is replaced with a tombstone, with the real address
 * preserved in `registration_email`.
 *
 * A `not_archived` global scope hides archived rows from every normal query
 * (registration queue, login, dashboards, uniqueness checks); they remain
 * available with `withoutGlobalScope('not_archived')` for auditing.
 *
 * The model must have these nullable columns:
 *   • archived_at        (timestamp)
 *   • registration_email (string)
 */
trait HasRegistrationArchive
{
    protected static function bootHasRegistrationArchive(): void
    {
        static::addGlobalScope('not_archived', function ($query) {
            $query->whereNull($query->getModel()->getTable().'.archived_at');
        });
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Archive this row so its e-mail can be reused by a new application. The
     * caller is responsible for deleting the row's private documents.
     */
    public function archiveRegistration(): void
    {
        // Tombstone the unique email and free the phone number so a retry can
        // reuse both. MySQL unique indexes allow multiple NULL contact_no values.
        $this->forceFill([
            'registration_email' => $this->email,
            'email' => 'archived-'.$this->getKey().'@archive.invalid',
            'contact_no' => null,
            'archived_at' => now(),
        ])->save();
    }

    /**
     * An unverified row is "abandoned" only once it is older than the 10-minute
     * verification window AND its code has expired. Until then a newcomer who
     * submits the same address must NOT be allowed to replace it — that could
     * wipe the real owner's half-finished registration.
     */
    public function isAbandonedRegistration(): bool
    {
        if ($this->created_at === null || $this->created_at->gt(now()->subMinutes(10))) {
            return false;
        }

        return method_exists($this, 'isOtpExpired') ? $this->isOtpExpired() : true;
    }
}
