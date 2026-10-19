<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Notifications\Admin\AccountStatusChanged;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Minimal user management: list approved/suspended accounts and
 * suspend or reactivate sellers, logistics providers, and buyers.
 */
class UserAccountController extends Controller
{
    public function index(Request $request): View
    {
        $type = (string) $request->query('type', 'seller');
        if (! in_array($type, ['buyer', 'seller', 'logistics'], true)) {
            $type = 'seller';
        }

        $search = mb_substr(trim((string) $request->query('search')), 0, 100);

        $accounts = match ($type) {
            'buyer' => $this->queryAccounts(Buyer::query(), $search),
            'logistics' => $this->queryAccounts(LogisticsProvider::query(), $search, withBusinessName: true),
            default => $this->queryAccounts(Seller::query(), $search, withBusinessName: true),
        };

        return view('Admin.Users.index', [
            'accounts' => $accounts->paginate(25)->withQueryString(),
            'type' => $type,
            'search' => $search,
        ]);
    }

    public function suspendBuyer(Buyer $buyer): RedirectResponse
    {
        return $this->setStatus($buyer, 'suspended', 'buyer');
    }

    public function reactivateBuyer(Buyer $buyer): RedirectResponse
    {
        return $this->setStatus($buyer, 'approved', 'buyer');
    }

    public function suspendSeller(Seller $seller): RedirectResponse
    {
        return $this->setStatus($seller, 'suspended', 'seller');
    }

    public function reactivateSeller(Seller $seller): RedirectResponse
    {
        return $this->setStatus($seller, 'approved', 'seller');
    }

    public function suspendLogistics(LogisticsProvider $logisticsProvider): RedirectResponse
    {
        return $this->setStatus($logisticsProvider, 'suspended', 'logistics');
    }

    public function reactivateLogistics(LogisticsProvider $logisticsProvider): RedirectResponse
    {
        return $this->setStatus($logisticsProvider, 'approved', 'logistics');
    }

    private function queryAccounts($query, string $search, bool $withBusinessName = false)
    {
        $query->whereIn('status', ['approved', 'suspended'])->orderByDesc('updated_at');

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

    private function setStatus(Buyer|Seller|LogisticsProvider $account, string $to, string $label): RedirectResponse
    {
        $current = $account->status instanceof \BackedEnum
            ? $account->status->value
            : (string) $account->status;

        $from = $to === 'suspended' ? 'approved' : 'suspended';

        if ($current !== $from) {
            return back()->with('error', "Only {$from} {$label} accounts can be changed to {$to}.");
        }

        $account->forceFill(['status' => $to])->save();
        $account->notify(new AccountStatusChanged($to));

        $verb = $to === 'suspended' ? 'suspended' : 'reactivated';

        return back()->with('success', "{$account->fullName()}'s {$label} account was {$verb}.");
    }
}
