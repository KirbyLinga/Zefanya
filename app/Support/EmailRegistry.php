<?php

namespace App\Support;

use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * One email address must belong to exactly ONE role across the account
 * tables: buyers, sellers, logistics_providers, riders and admins.
 *
 * Registration requests use this to reject an address that already belongs to
 * another role, and the unified login uses it to refuse an address that is
 * linked to more than one role (which would be ambiguous to sign in to).
 *
 * Archived registration rows (see HasRegistrationArchive) carry a tombstone
 * email, so they are naturally excluded.
 */
class EmailRegistry
{
    /** Same pattern as EMAIL_RE in resources/js/registration-validation.js: name, @, domain with a dot, no spaces. */
    public const FORMAT_REGEX = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';

    public const FORMAT_MESSAGE = 'Enter a valid email address with a domain.';

    /** Shared format rules; callers must trim and lowercase the value first. @return array<int, string> */
    public static function formatRules(): array
    {
        return ['required', 'string', 'email', 'max:255', 'regex:'.self::FORMAT_REGEX];
    }

    /** Trim surrounding spaces and lowercase, the stored form of every account email. */
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }

    private static function registrationModel(string $role): string
    {
        return match ($role) {
            'buyer' => Buyer::class,
            'seller' => Seller::class,
            'logistics' => LogisticsProvider::class,
            default => throw new \InvalidArgumentException('Unsupported registration role.'),
        };
    }

    /**
     * How long an identical re-sent registration counts as a double submit
     * (double click, network retry). Kept short so a person deliberately
     * registering the same email again still sees "already registered".
     */
    public const DUPLICATE_SUBMIT_SECONDS = 10;

    /** Fields that must match exactly, besides email and password, for a double submit. */
    private const DUPLICATE_FIELDS = ['first_name', 'last_name', 'contact_no'];

    /**
     * The applicant's own just-created, still-unverified registration when the
     * identical form is submitted again: same role, email, password, name and
     * mobile, created seconds ago. Callers return the original success
     * response instead of an error or a second OTP.
     *
     * @param  array<string, mixed>|null  $submission  password, first_name, last_name, contact_no
     */
    public static function duplicateSubmission(string $email, string $role, ?array $submission, bool $lock = false): mixed
    {
        $password = $submission['password'] ?? null;
        if (! is_string($password) || $password === '') {
            return null;
        }

        $model = self::registrationModel($role);
        $query = $model::whereRaw('LOWER(email) = ?', [self::normalize($email)])
            ->where('status', 'unverified')
            ->whereNull('archived_at')
            ->where('created_at', '>=', now()->subSeconds(self::DUPLICATE_SUBMIT_SECONDS));

        foreach (self::DUPLICATE_FIELDS as $field) {
            $query->where($field, (string) ($submission[$field] ?? ''));
        }

        $account = $lock ? $query->lockForUpdate()->first() : $query->first();

        return $account !== null && Hash::check($password, (string) $account->password) ? $account : null;
    }

    /**
     * The same policy is checked by validation and again before persistence.
     *
     * @param  array<string, mixed>|null  $submission  see duplicateSubmission()
     */
    public static function registrationError(string $email, string $role, bool $lock = false, ?array $submission = null): ?string
    {
        $email = strtolower(trim($email));
        $model = self::registrationModel($role);
        $account = $model::whereRaw('LOWER(email) = ?', [$email])->first();

        if ($lock && $account !== null) {
            $account = $model::whereKey($account->getKey())->whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->first();
        }

        if ($account !== null && ! in_array($account->getRawOriginal('status'), ['unverified', 'rejected'], true)) {
            return 'This email is already registered. Use a different email.';
        }

        $foreign = self::otherRolesFor($email, $role);
        if ($foreign !== []) {
            return 'This email is already registered as a '.self::labelList($foreign).'. Use a different email.';
        }

        if ($account !== null && $account->getRawOriginal('status') === 'unverified' && ! $account->isAbandonedRegistration()) {
            if (self::duplicateSubmission($email, $role, $submission) !== null) {
                return null;
            }

            return 'This email is currently awaiting verification. Please finish verifying it, then try again later.';
        }

        return null;
    }

    /**
     * Serialize these three registration entry points across account tables.
     * A duplicate submission returns the existing account (wasRecentlyCreated
     * false), so the caller sends no second OTP.
     */
    public static function register(string $email, string $role, Closure $create, ?array $submission = null): mixed
    {
        $connection = DB::connection();
        $mysql = $connection->getDriverName() === 'mysql';
        $lock = 'registration-email:'.hash('sha1', $connection->getDatabaseName().'|'.strtolower(trim($email)));

        if ($mysql && (int) $connection->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lock])->acquired !== 1) {
            throw ValidationException::withMessages(['email' => ['Another registration is processing this email. Please try again.']]);
        }

        try {
            return $connection->transaction(function () use ($email, $role, $create, $submission) {
                if (($existing = self::duplicateSubmission($email, $role, $submission, lock: true)) !== null) {
                    return $existing;
                }

                if ($error = self::registrationError($email, $role, lock: true)) {
                    throw ValidationException::withMessages(['email' => [$error]]);
                }

                return $create();
            });
        } finally {
            if ($mysql) {
                $connection->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            }
        }
    }

    /** Map a unique-index race to the fields that now conflict, without exposing SQL. */
    public static function registrationConflictErrors(string $email, string $role, string $phone): array
    {
        $errors = [];
        if ($error = self::registrationError($email, $role)) {
            $errors['email'] = [$error];
        }
        $model = self::registrationModel($role);
        if ($model::where('contact_no', $phone)->where(function ($query) use ($email): void {
            $query->whereRaw('LOWER(email) != ?', [strtolower(trim($email))])
                ->orWhereNotIn('status', ['unverified', 'rejected']);
        })->exists()) {
            $errors['contact_no'] = [PhilippineMobile::TAKEN_MESSAGE];
        }

        return $errors ?: ['email' => ['This email or mobile number is already registered.']];
    }

    /**
     * Role key → table + human label.
     *
     * @var array<string, array{table: string, label: string}>
     */
    private const SOURCES = [
        'buyer' => ['table' => 'buyers', 'label' => 'buyer'],
        'seller' => ['table' => 'sellers', 'label' => 'seller'],
        'logistics' => ['table' => 'logistics_providers', 'label' => 'logistics provider'],
        'admin' => ['table' => 'admins', 'label' => 'administrator'],
        'rider' => ['table' => 'riders', 'label' => 'rider'],
    ];

    /**
     * Every role that owns this email (case-insensitive).
     *
     * @return list<string>
     */
    public static function rolesFor(string $email): array
    {
        $needle = strtolower(trim($email));

        if ($needle === '') {
            return [];
        }

        $roles = [];

        foreach (self::SOURCES as $role => $source) {
            $query = DB::table($source['table'])->whereRaw('LOWER(email) = ?', [$needle]);

            // Role tables archive leftover applicants; admins never archive.
            if (! in_array($role, ['admin', 'rider'], true)) {
                $query->whereNull('archived_at');
            }

            if ($query->exists()) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /**
     * Every role EXCEPT $ownRole that owns this email.
     *
     * @return list<string>
     */
    public static function otherRolesFor(string $email, string $ownRole): array
    {
        return array_values(array_diff(self::rolesFor($email), [$ownRole]));
    }

    public static function label(string $role): string
    {
        return self::SOURCES[$role]['label'] ?? $role;
    }

    /**
     * Human list of roles, e.g. "seller and buyer".
     *
     * @param  list<string>  $roles
     */
    public static function labelList(array $roles): string
    {
        $labels = array_map([self::class, 'label'], $roles);

        if (count($labels) <= 1) {
            return $labels[0] ?? '';
        }

        $last = array_pop($labels);

        return implode(', ', $labels).' and '.$last;
    }
}
