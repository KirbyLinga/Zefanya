<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\URL;

/**
 * Signed links for the three self-registration OTP endpoints.
 *
 * The OTP routes take a numeric id, which is trivially guessable. Every link is
 * therefore a Laravel temporary signed URL (valid for 60 minutes): changing the
 * id invalidates the signature, so a visitor can never open — or POST to —
 * another person's verification.
 */
class SignedOtpLink
{
    /** How long a link stays valid, in minutes. */
    public const TTL_MINUTES = 60;

    /**
     * role → the three route names + the route parameter that carries the model.
     *
     * @var array<string, array{show: string, verify: string, resend: string, param: string}>
     */
    private const ROUTES = [
        'rider' => [
            'show' => 'register.rider.verify-otp.show',
            'verify' => 'register.rider.verify-otp',
            'resend' => 'register.rider.verify-otp.resend',
            'param' => 'riderApplication',
        ],
        'buyer' => [
            'show' => 'register.buyer.verify-otp.show',
            'verify' => 'register.buyer.verify-otp',
            'resend' => 'register.buyer.verify-otp.resend',
            'param' => 'buyer',
        ],
        'seller' => [
            'show' => 'register.seller.verify-otp.show',
            'verify' => 'register.seller.verify-otp',
            'resend' => 'register.seller.verify-otp.resend',
            'param' => 'seller',
        ],
        'logistics' => [
            'show' => 'register.logistics.verify-otp',
            'verify' => 'register.logistics.verify-otp.store',
            'resend' => 'register.logistics.verify-otp.resend',
            'param' => 'logistics_provider',
        ],
    ];

    public static function showUrl(string $role, Model $model): string
    {
        return self::sign($role, 'show', $model);
    }

    public static function verifyUrl(string $role, Model $model): string
    {
        return self::sign($role, 'verify', $model);
    }

    public static function resendUrl(string $role, Model $model): string
    {
        return self::sign($role, 'resend', $model);
    }

    private static function sign(string $role, string $action, Model $model): string
    {
        $route = self::ROUTES[$role];

        return URL::temporarySignedRoute(
            $route[$action],
            now()->addMinutes(self::TTL_MINUTES),
            [$route['param'] => $model],
        );
    }
}
