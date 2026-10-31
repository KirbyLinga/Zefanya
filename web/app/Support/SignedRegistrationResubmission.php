<?php

namespace App\Support;

use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use Illuminate\Support\Facades\URL;

class SignedRegistrationResubmission
{
    public static function url(Seller|LogisticsProvider $account): string
    {
        return URL::temporarySignedRoute('registration.resubmit', now()->addHour(), [
            'role' => $account instanceof Seller ? 'seller' : 'logistics',
            'application' => $account->getKey(),
        ]);
    }
}
