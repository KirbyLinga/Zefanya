<?php

namespace App\Enums;

use Illuminate\Support\Facades\Auth;

enum AuthRole: string
{
    case Admin = 'admin';
    case Seller = 'seller';
    case Logistics = 'logistics';
    case Rider = 'rider';
    case Buyer = 'buyer';

    public function guard(): string
    {
        return $this->value;
    }

    public function dashboardRoute(): string
    {
        return match ($this) {
            self::Admin => 'admin.dashboard',
            self::Seller => 'seller.dashboard',
            self::Logistics => 'logistics.dashboard',
            self::Rider => 'rider.dashboard',
            self::Buyer => 'buyer.home',
        };
    }

    public function dashboardUrl(): string
    {
        return route($this->dashboardRoute());
    }

    /**
     * Session detection and ClearsOtherGuards order.
     *
     * @return list<self>
     */
    public static function detectionOrder(): array
    {
        if (Auth::guard('rider')->check()) {
            return [self::Admin, self::Seller, self::Logistics, self::Buyer, self::Rider];
        }

        return [self::Admin, self::Seller, self::Logistics, self::Buyer];
    }

    /**
     * Public login + Google role resolution order. Admin is never included.
     *
     * @return list<self>
     */
    public static function publicOrder(): array
    {
        return [self::Seller, self::Logistics, self::Buyer];
    }

    /**
     * @return list<string>
     */
    public static function publicGuards(): array
    {
        return array_map(fn (self $role): string => $role->value, self::publicOrder());
    }

    /** Read-only: first signed-in role, or null. Never logs anyone out. */
    public static function current(): ?self
    {
        foreach (self::detectionOrder() as $role) {
            if (Auth::guard($role->guard())->check()) {
                return $role;
            }
        }

        return null;
    }
}
