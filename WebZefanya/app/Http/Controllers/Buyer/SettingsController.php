<?php

namespace App\Http\Controllers\Buyer;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Shared\Order;
use App\Services\Buyer\BuyerAccountDeletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /**
     * GET /buyer/settings — Display settings page
     */
    public function index(Request $request, BuyerAccountDeletionService $deletion): View
    {
        $buyer = $request->user('buyer');

        // Initialize settings with defaults if null
        $defaultSettings = [
            'notifications' => [
                'order_updates' => ['email' => true, 'sms' => true],
                'promotions' => ['email' => true, 'sms' => false],
                'wishlist_drops' => ['email' => true, 'sms' => false],
            ],
            'appearance' => 'system',
        ];

        $settings = $buyer->settings ?? $defaultSettings;

        // Ensure all keys exist (merge with defaults)
        $settings = array_replace_recursive($defaultSettings, $settings ?? []);

        return view('Buyer.Settings.index', [
            'buyer' => $buyer,
            'counts' => $this->getOrderCounts($buyer),
            'settings' => $settings,
            'deletionBlockedBy' => $deletion->blockingReason($buyer),
        ]);
    }

    /**
     * PATCH /buyer/settings — Update notification and appearance preferences
     */
    public function update(Request $request): RedirectResponse
    {
        $buyer = $request->user('buyer');

        $data = $request->validate([
            'notifications.order_updates.email' => ['nullable', 'boolean'],
            'notifications.order_updates.sms' => ['nullable', 'boolean'],
            'notifications.promotions.email' => ['nullable', 'boolean'],
            'notifications.promotions.sms' => ['nullable', 'boolean'],
            'notifications.wishlist_drops.email' => ['nullable', 'boolean'],
            'notifications.wishlist_drops.sms' => ['nullable', 'boolean'],
            'appearance' => ['required', 'in:light,dark,system'],
        ]);

        // Build settings structure
        $settings = $buyer->settings ?? [];

        // Update notifications
        if (isset($data['notifications'])) {
            $settings['notifications'] = [
                'order_updates' => [
                    'email' => $data['notifications']['order_updates']['email'] ?? false,
                    'sms' => $data['notifications']['order_updates']['sms'] ?? false,
                ],
                'promotions' => [
                    'email' => $data['notifications']['promotions']['email'] ?? false,
                    'sms' => $data['notifications']['promotions']['sms'] ?? false,
                ],
                'wishlist_drops' => [
                    'email' => $data['notifications']['wishlist_drops']['email'] ?? false,
                    'sms' => $data['notifications']['wishlist_drops']['sms'] ?? false,
                ],
            ];
        }

        // Update appearance
        if (isset($data['appearance'])) {
            $settings['appearance'] = $data['appearance'];
        }

        $buyer->update(['settings' => $settings]);

        return redirect()
            ->route('buyer.settings.index')
            ->with('success', 'Settings updated successfully.');
    }

    /**
     * POST /buyer/settings/delete-account — Anonymize the buyer account (requires confirmation)
     */
    public function deleteAccount(Request $request, BuyerAccountDeletionService $deletion): RedirectResponse
    {
        $buyer = $request->user('buyer');

        // Accounts created with Google have no password to confirm, so they retype their email.
        $hasPassword = $buyer->password !== null;
        $data = $request->validate([
            'password' => [$hasPassword ? 'required' : 'nullable', 'string'],
            'confirm_email' => [$hasPassword ? 'nullable' : 'required', 'string'],
            'confirm' => ['accepted'],
        ], [
            'password.required' => 'Enter your password to confirm account deletion.',
            'confirm_email.required' => 'Enter your email to confirm account deletion.',
            'confirm.accepted' => 'Confirm that you understand this action is permanent.',
        ]);

        if ($hasPassword && ! Hash::check($data['password'], $buyer->password)) {
            return back()->withErrors(['password' => 'That password doesn\'t match your account.']);
        }
        if (! $hasPassword && strcasecmp(trim($data['confirm_email']), $buyer->email) !== 0) {
            return back()->withErrors(['confirm_email' => 'That email doesn\'t match your account.']);
        }

        $deletion->delete($buyer);

        // Log out and redirect to home
        auth('buyer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('home')
            ->with('success', 'Your account has been deleted.');
    }

    /**
     * Calculate order counts for the sidebar (same as AccountController).
     */
    private function getOrderCounts(Buyer $buyer): array
    {
        $allOrdersForCounts = Order::query()
            ->where('buyer_id', $buyer->id)
            ->with('sellerOrders:id,order_id,status')
            ->get(['id']);

        $counts = [
            'all' => $allOrdersForCounts->count(),
            'to_ship' => 0,
            'to_receive' => 0,
            'completed' => 0,
            'cancelled' => 0,
        ];

        $toShipStatuses = [
            OrderStatus::Placed,
            OrderStatus::Confirmed,
            OrderStatus::Preparing,
            OrderStatus::ReadyForPickup,
        ];

        $toReceiveStatuses = [
            OrderStatus::AssignedToRider,
            OrderStatus::PickedUp,
            OrderStatus::AtSortingCenter,
            OrderStatus::Sorted,
            OrderStatus::InTransit,
            OrderStatus::ArrivedAtHub,
            OrderStatus::OutForDelivery,
            OrderStatus::Delivered,
        ];

        $cancelledStatuses = [
            OrderStatus::DeliveryFailed,
            OrderStatus::Returned,
        ];

        foreach ($allOrdersForCounts as $o) {
            $statuses = $o->sellerOrders->pluck('status');
            if ($statuses->contains(fn ($s) => $s === OrderStatus::Completed)) {
                $counts['completed']++;
            } elseif ($statuses->contains(fn ($s) => in_array($s, $toReceiveStatuses, true))) {
                $counts['to_receive']++;
            } elseif ($statuses->contains(fn ($s) => in_array($s, $toShipStatuses, true))) {
                $counts['to_ship']++;
            } elseif ($statuses->contains(fn ($s) => in_array($s, $cancelledStatuses, true))) {
                $counts['cancelled']++;
            }
        }

        return $counts;
    }
}
