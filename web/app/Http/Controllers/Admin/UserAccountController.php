<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Notifications\Admin\AccountStatusChanged;
use App\Services\ProviderProvinceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Minimal user management: list approved/suspended accounts and
 * suspend or reactivate sellers, logistics providers, and buyers.
 *
 * On suspension, the account's existing web sessions and any outstanding
 * remember-me token are revoked so the account cannot keep using the app
 * until the session expires naturally.
 */
class UserAccountController extends Controller
{
    public function index(Request $request, ProviderProvinceService $provinces): View
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

        $paginator = $accounts->paginate(25)->withQueryString();

        $provinceBlockers = [];
        if ($type === 'logistics') {
            foreach ($paginator as $account) {
                $provinceBlockers[$account->id] = $provinces->provinceChangeBlockers($account);
            }
        }

        return view('Admin.Users.index', [
            'accounts' => $paginator,
            'type' => $type,
            'search' => $search,
            'provinceBlockers' => $provinceBlockers,
        ]);
    }

    public function suspendBuyer(Buyer $buyer): RedirectResponse
    {
        return $this->setStatus($buyer, 'suspended', 'buyer');
    }

    public function reactivateBuyer(Buyer $buyer): RedirectResponse
    {
        $this->revokeAccountSessionsAndRememberToken($buyer);

        return $this->setStatus($buyer, 'approved', 'buyer');
    }

    public function suspendSeller(Seller $seller): RedirectResponse
    {
        return $this->setStatus($seller, 'suspended', 'seller');
    }

    public function reactivateSeller(Seller $seller): RedirectResponse
    {
        $this->revokeAccountSessionsAndRememberToken($seller);

        return $this->setStatus($seller, 'approved', 'seller');
    }

    public function suspendLogistics(LogisticsProvider $logisticsProvider): RedirectResponse
    {
        return $this->setStatus($logisticsProvider, 'suspended', 'logistics');
    }

    public function reactivateLogistics(LogisticsProvider $logisticsProvider): RedirectResponse
    {
        $this->revokeAccountSessionsAndRememberToken($logisticsProvider);

        return $this->setStatus($logisticsProvider, 'approved', 'logistics');
    }

    private function queryAccounts(Builder $query, string $search, bool $withBusinessName = false): Builder
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

        if ($to === 'suspended') {
            $this->revokeAccountSessionsAndRememberToken($account);
        }

        $verb = $to === 'suspended' ? 'suspended' : 'reactivated';

        return back()->with('success', "{$account->fullName()}'s {$label} account was {$verb}.");
    }

    /**
     * Revoke every active session for the given account and cycle its
     * remember-me token, when the session driver is the database driver.
     */
    private function revokeSessionsAndRememberToken(Buyer|Seller|LogisticsProvider $account): void
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
            DB::table($table)
                ->where('user_id', $userId)
                ->delete();
        }

        $rememberToken = $account->getRememberToken();
        if ($rememberToken !== null) {
            $account->setRememberToken(null);
            $account->saveQuietly();
        }
    }

    /**
     * Clear the given seller's active database sessions and cycle its
     * remember-me token when the session driver is the database driver.
     */
    private function clearSellerSessionsAndRememberToken(Seller $seller): void
    {
        $table = config('session.table');

        if (empty($table) || config('session.driver') !== 'database') {
            return;
        }

        $userId = $seller->getAuthIdentifier();

        if (! is_int($userId) && ! ctype_digit((string) $userId)) {
            return;
        }

        DB::table($table)->where('user_id', $userId)->delete();

        if ($seller->getRememberToken() !== null) {
            $seller->setRememberToken(null);
            $seller->saveQuietly();
        }
    }

    /**
     * Clear the given logistics provider's active database sessions and cycle
     * its remember-me token when the session driver is the database driver.
     */
    private function clearLogisticsSessionsAndRememberToken(LogisticsProvider $provider): void
    {
        $table = config('session.table');

        if (empty($table) || config('session.driver') !== 'database') {
            return;
        }

        $userId = $provider->getAuthIdentifier();

        if (! is_int($userId) && ! ctype_digit((string) $userId)) {
            return;
        }

        DB::table($table)->where('user_id', $userId)->delete();

        if ($provider->getRememberToken() !== null) {
            $provider->setRememberToken(null);
            $provider->saveQuietly();
        }
    }
}
