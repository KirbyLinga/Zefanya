<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerDebt;
use App\Models\Shared\SellerOrder;
use App\Notifications\Admin\AccountStatusChanged;
use App\Services\Admin\AccountStatusService;
use App\Services\Logistics\ProviderProvinceService;
use App\Services\Shared\AccountSessionService;
use App\Support\RuntimeSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * User management: search accounts by type and status, and
 * suspend or reactivate sellers, logistics providers, and buyers.
 *
 * On suspension, the account's existing web sessions and any outstanding
 * remember-me token are revoked so the account cannot keep using the app
 * until the session expires naturally.
 */
class UserAccountController extends Controller
{
    private const ACCOUNT_STATUSES = [
        'approved' => 'Approved',
        'pending' => 'Pending',
        'suspended' => 'Suspended',
        'rejected' => 'Rejected',
        'unverified' => 'Unverified',
    ];

    public function __construct(private AccountStatusService $accountStatuses, private AccountSessionService $accountSessions) {}

    public function index(Request $request, ProviderProvinceService $provinces): View
    {
        $type = (string) $request->query('type', 'seller');
        if (! in_array($type, ['buyer', 'seller', 'logistics'], true)) {
            $type = 'seller';
        }

        $status = $request->query('status', '');
        if (! is_string($status) || ! array_key_exists($status, self::ACCOUNT_STATUSES)) {
            $status = '';
        }

        $search = mb_substr(trim((string) $request->query('search')), 0, 100);

        $accounts = match ($type) {
            'buyer' => $this->queryAccounts(Buyer::query(), $search, $status),
            'logistics' => $this->queryAccounts(LogisticsProvider::query(), $search, $status, withBusinessName: true),
            default => $this->queryAccounts(Seller::query(), $search, $status, withBusinessName: true),
        };

        $paginator = $accounts->paginate(25)->withQueryString();

        $sellerDebt = $type === 'seller' && RuntimeSchema::hasTable('seller_debts')
            ? SellerDebt::query()->outstanding()->whereIn('seller_id', $paginator->getCollection()->modelKeys())
                ->groupBy('seller_id')->selectRaw('seller_id, SUM(amount_minor - settled_minor) AS unpaid_minor')
                ->pluck('unpaid_minor', 'seller_id')
            : collect();
        $provinceBlockers = [];
        $outForDelivery = [];
        if ($type === 'logistics') {
            foreach ($paginator as $account) {
                $provinceBlockers[$account->id] = $provinces->provinceChangeBlockers($account);
            }

            // Parcels a suspended company's riders still hold: what a wind-down would let them finish.
            $outForDelivery = SellerOrder::query()
                ->join('couriers', 'couriers.id', '=', 'seller_orders.delivery_courier_id')
                ->whereIn('couriers.logistics_provider_id', $paginator->getCollection()->pluck('id'))
                ->where('seller_orders.status', OrderStatus::OutForDelivery->value)
                ->groupBy('couriers.logistics_provider_id')
                ->selectRaw('couriers.logistics_provider_id, COUNT(*) as aggregate')
                ->pluck('aggregate', 'couriers.logistics_provider_id')
                ->map(fn ($count): int => (int) $count)
                ->all();
        }

        return view('Admin.Users.index', [
            'accounts' => $paginator,
            'type' => $type,
            'search' => $search,
            'status' => $status,
            'statuses' => self::ACCOUNT_STATUSES,
            'provinceBlockers' => $provinceBlockers,
            'outForDelivery' => $outForDelivery,
            'sellerDebt' => $sellerDebt,
            'maxWindDownHours' => AccountStatusService::MAX_RIDER_WIND_DOWN_HOURS,
        ]);
    }

    public function suspendBuyer(Request $request, Buyer $buyer): RedirectResponse
    {
        return $this->setStatus($request, $buyer, 'suspended', 'buyer');
    }

    public function reactivateBuyer(Request $request, Buyer $buyer): RedirectResponse
    {
        return $this->setStatus($request, $buyer, 'approved', 'buyer');
    }

    public function suspendSeller(Request $request, Seller $seller): RedirectResponse
    {
        return $this->setStatus($request, $seller, 'suspended', 'seller');
    }

    public function reactivateSeller(Request $request, Seller $seller): RedirectResponse
    {
        return $this->setStatus($request, $seller, 'approved', 'seller');
    }

    public function suspendLogistics(Request $request, LogisticsProvider $logisticsProvider): RedirectResponse
    {
        return $this->setStatus($request, $logisticsProvider, 'suspended', 'logistics');
    }

    public function reactivateLogistics(Request $request, LogisticsProvider $logisticsProvider): RedirectResponse
    {
        return $this->setStatus($request, $logisticsProvider, 'approved', 'logistics');
    }

    /**
     * Let a suspended company's riders finish parcels already out for delivery
     * for a limited time (0 hours ends the window).
     */
    public function riderWindDownLogistics(Request $request, LogisticsProvider $logisticsProvider): RedirectResponse
    {
        $data = $request->validate([
            'hours' => ['required', 'integer', 'min:0', 'max:'.AccountStatusService::MAX_RIDER_WIND_DOWN_HOURS],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        $hours = (int) $data['hours'];

        if (! $this->accountStatuses->setRiderWindDown($logisticsProvider, $request->user('admin'), $hours, $data['reason'])) {
            return back()->with('error', 'A rider wind-down is only available for suspended logistics accounts.');
        }

        return back()->with('success', $hours === 0
            ? "{$logisticsProvider->fullName()}'s rider wind-down ended. Its riders can no longer work."
            : "{$logisticsProvider->fullName()}'s riders may finish parcels already out for delivery for the next {$hours} hours.");
    }

    private function queryAccounts(Builder $query, string $search, string $status, bool $withBusinessName = false): Builder
    {
        $query->whereIn('status', array_keys(self::ACCOUNT_STATUSES))->orderByDesc('updated_at');

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like, $withBusinessName): void {
                $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('contact_no', 'like', $like);

                if ($withBusinessName) {
                    $q->orWhere('business_name', 'like', $like);
                }
            });
        }

        return $query;
    }

    private function setStatus(Request $request, Buyer|Seller|LogisticsProvider $account, string $to, string $label): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $from = $to === 'suspended' ? 'approved' : 'suspended';

        if (! $this->accountStatuses->transition($account, $request->user('admin'), $to, $data['reason'])) {
            return back()->with('error', "Only {$from} {$label} accounts can be changed to {$to}.");
        }

        $account->refresh();
        $this->revokeAccountSessionsAndRememberToken($account);
        $account->notify(new AccountStatusChanged($to));

        $verb = $to === 'suspended' ? 'suspended' : 'reactivated';

        return back()->with('success', "{$account->fullName()}'s {$label} account was {$verb}.");
    }

    /**
     * Revoke every active session for the given account and cycle its
     * remember-me token, when the session driver is the database driver.
     */
    private function revokeAccountSessionsAndRememberToken(Buyer|Seller|LogisticsProvider $account): void
    {
        $table = config('session.table');

        if (empty($table)) {
            return;
        }

        $userId = $account->getAuthIdentifier();

        if (! is_int($userId) && ! ctype_digit((string) $userId)) {
            return;
        }

        if (config('session.driver') === 'database') {
            $guard = match (true) {
                $account instanceof Buyer => 'buyer',
                $account instanceof Seller => 'seller',
                $account instanceof LogisticsProvider => 'logistics',
            };

            $this->accountSessions->revoke($guard, $userId);
        }

        // getRememberToken() casts the column to string, so a NULL token reads
        // back as ''. Check the stored attribute to avoid a pointless save on
        // accounts that never had a remember-me token.
        if ($account->remember_token !== null) {
            $account->setRememberToken(null);
            $account->saveQuietly();
        }
    }
}
