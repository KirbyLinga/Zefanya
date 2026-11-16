<?php

namespace App\Services\Shared;

use App\Enums\AuthRole;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** Revoke database sessions only when their guard login key identifies the account. */
class AccountSessionService
{
    /** @param  Closure(Builder): void|null  $scope */
    public function revoke(string $guard, int|string $accountId, ?Closure $scope = null): void
    {
        if (config('session.driver') !== 'database' || ! config('session.table')) {
            return;
        }

        if (AuthRole::tryFrom($guard) === null) {
            throw new \InvalidArgumentException('Unsupported account session guard.');
        }

        $loginKey = Auth::guard($guard)->getName();
        $query = DB::connection(config('session.connection'))->table(config('session.table'))
            ->where(function (Builder $query) use ($accountId): void {
                // The default web guard can leave user_id null immediately after
                // role-specific login. The payload is the authoritative identity.
                $query->where('user_id', $accountId)->orWhereNull('user_id');
            });

        if ($scope !== null) {
            $scope($query);
        }

        $query->select(['id', 'payload'])->chunkById(100, function ($sessions) use ($query, $loginKey, $accountId): void {
            foreach ($sessions as $session) {
                $attributes = $this->attributes($session->payload);
                $identity = $attributes[$loginKey] ?? null;

                if ((! is_int($identity) && ! is_string($identity)) || (string) $identity !== (string) $accountId) {
                    continue;
                }

                // A concurrent login may have replaced the payload since the read.
                // Never delete a session that no longer has the checked identity.
                (clone $query)->where('id', $session->id)->where('payload', $session->payload)->delete();
            }
        });
    }

    private function attributes(string $payload): array
    {
        $serialized = base64_decode($payload, true);

        if ($serialized === false) {
            return [];
        }

        if (config('session.encrypt')) {
            try {
                $serialized = app('encrypter')->decrypt($serialized);
            } catch (DecryptException) {
                return [];
            }
        }

        if (! is_string($serialized)) {
            return [];
        }

        $attributes = config('session.serialization', 'php') === 'json'
            ? json_decode($serialized, true)
            : @unserialize($serialized, ['allowed_classes' => false]);

        // Unidentifiable sessions must not be guessed from a numeric user_id.
        // Existing account-status middleware still blocks suspended accounts.
        return is_array($attributes) ? $attributes : [];
    }
}
