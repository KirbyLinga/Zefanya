<?php

namespace App\View\Composers;

use App\Models\Rider\Rider;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Supplies the notification bell on every Rider panel page.
 *
 * A composer keeps the two queries out of the layout file and means new rider
 * screens get the bell automatically without passing props from each
 * controller.
 */
class RiderNotificationComposer
{
    public function compose(View $view): void
    {
        $rider = Auth::guard('rider')->user();

        if (! $rider instanceof Rider) {
            return;
        }

        $view->with([
            'riderBellNotifications' => $rider->notifications()->latest()->limit(8)->get(),
            'riderBellUnreadCount' => $rider->unreadNotifications()->count(),
        ]);
    }
}
