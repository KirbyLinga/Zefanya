<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Notifications\Buyer\BuyerRegistrationDecision;
use App\Notifications\Logistics\LogisticsRegistrationDecision;
use App\Notifications\Seller\SellerRegistrationDecision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class RegistrationController extends Controller
{
    /**
     * Registry filter values the unified queue accepts (?filter=…).
     */
    private const FILTERS = ['all', 'buyer', 'seller', 'logistics'];

    /**
     * Unified pending registration queue — buyers, sellers and logistics
     * providers together, sorted by submission time.
     *
     * Query params:
     *   ?filter=buyer|seller|logistics  which registry to list (default: all).
     *   ?q=<text>                       free-text search over name, email,
     *                                    contact number and business name.
     *   ?view=<id>&type=<registry>      which applicant the detail panel shows
     *                                    (legacy params — deliberately kept, so
     *                                    the list filter is named `filter`).
     */
    public function index(Request $request)
    {
        if (! Schema::hasTable('buyers') || ! Schema::hasTable('sellers')) {
            abort(404, 'Registration tables haven\'t been created yet — this feature isn\'t available.');
        }

        $filter = (string) $request->query('filter', 'all');
        if (! in_array($filter, self::FILTERS, true)) {
            $filter = 'all';
        }

        // Cap the term so a pasted novel can't build a huge LIKE clause.
        $search = mb_substr(trim((string) $request->query('q')), 0, 100);

        $buyersQuery = Buyer::query()->where('status', 'pending_approval');
        $sellersQuery = Seller::query()->where('status', 'pending_approval');

        // Logistics providers live in their own table that ships with this
        // feature — skip the query entirely on installs that haven't migrated
        // it yet instead of breaking the whole queue.
        $logisticsQuery = Schema::hasTable('logistics_providers')
            ? LogisticsProvider::query()->where('status', 'pending_approval')
            : null;

        $this->applySearch($buyersQuery, $search);
        $this->applySearch($sellersQuery, $search, withBusinessName: true);
        if ($logisticsQuery) {
            $this->applySearch($logisticsQuery, $search, withBusinessName: true);
        }

        // Chips show how many matches each registry has under the current
        // search, so the counts stay consistent with what listing them shows.
        $counts = [
            'buyer' => (clone $buyersQuery)->count(),
            'seller' => (clone $sellersQuery)->count(),
            'logistics' => $logisticsQuery ? (clone $logisticsQuery)->count() : 0,
        ];

        // Rows are only SELECTed for the registry(s) the active filter needs —
        // the counts above still cover all three, because the chips do.
        $pending = collect();

        if ($filter === 'all' || $filter === 'buyer') {
            $pending = $pending->concat(
                $buyersQuery->orderBy('created_at')->get()
                    ->map(fn (Buyer $b) => $this->queueRow('buyer', $b))
            );
        }

        if ($filter === 'all' || $filter === 'seller') {
            $pending = $pending->concat(
                $sellersQuery->orderBy('created_at')->get()
                    ->map(fn (Seller $s) => $this->queueRow('seller', $s))
            );
        }

        if (($filter === 'all' || $filter === 'logistics') && $logisticsQuery) {
            $pending = $pending->concat(
                $logisticsQuery->orderBy('created_at')->get()
                    ->map(fn (LogisticsProvider $l) => $this->queueRow('logistics', $l))
            );
        }

        $pending = $pending->sortBy('created_at');

        $selectedId = $request->query('view');
        $selectedType = $request->query('type');
        $selected = null;

        if ($selectedId && $selectedType) {
            $selected = match ($selectedType) {
                'seller' => Seller::find($selectedId),
                'logistics' => LogisticsProvider::find($selectedId),
                default => Buyer::find($selectedId),
            };
        }

        return view('Admin.Registrations.index', [
            'pending' => $pending,
            'selected' => $selected,
            'selectedType' => $selectedType,
            'filter' => $filter,
            'q' => $search,
            'counts' => $counts,
        ]);
    }

    /**
     * Narrow a pending-registration query with the free-text search. The term
     * matches first/last name, email, contact number and — where the table has
     * one — the business name. Wrapped in a single where() closure so the ORs
     * can never escape the `status = pending_approval` condition.
     */
    private function applySearch(Builder $query, string $search, bool $withBusinessName = false): void
    {
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';

        $query->where(function (Builder $q) use ($like, $withBusinessName) {
            $q->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('contact_no', 'like', $like);

            if ($withBusinessName) {
                $q->orWhere('business_name', 'like', $like);
            }
        });
    }

    /**
     * Shape one applicant row for the pending list (same shape as before).
     */
    private function queueRow(string $type, Buyer|Seller|LogisticsProvider $model): object
    {
        return (object) [
            'type' => $type,
            'model' => $model,
            'id' => $model->id,
            'name' => $model->fullName(),
            'email' => $model->email,
            'created_at' => $model->created_at,
        ];
    }

    public function approveBuyer(Buyer $buyer)
    {
        $buyer->update([
            'status' => 'approved',
            'approved_by' => Auth::guard('admin')->id(),
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        $buyer->notify(new BuyerRegistrationDecision('approved'));

        return back()->with('success', "{$buyer->fullName()}'s buyer registration was approved.");
    }

    public function rejectBuyer(Buyer $buyer, Request $request)
    {
        $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        $buyer->update([
            'status' => 'rejected',
            'approved_by' => Auth::guard('admin')->id(),
            'approved_at' => now(),
            'rejection_reason' => $request->rejection_reason,
        ]);

        $buyer->notify(new BuyerRegistrationDecision('rejected', $request->rejection_reason));

        return back()->with('success', "{$buyer->fullName()}'s buyer registration was rejected.");
    }

    public function approveSeller(Seller $seller)
    {
        $seller->update([
            'status' => 'approved',
            'approved_by' => Auth::guard('admin')->id(),
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        $seller->notify(new SellerRegistrationDecision('approved'));

        return back()->with('success', "{$seller->fullName()}'s seller registration was approved.");
    }

    public function rejectSeller(Seller $seller, Request $request)
    {
        $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        $seller->update([
            'status' => 'rejected',
            'approved_by' => Auth::guard('admin')->id(),
            'approved_at' => now(),
            'rejection_reason' => $request->rejection_reason,
        ]);

        $seller->notify(new SellerRegistrationDecision('rejected', $request->rejection_reason));

        return back()->with('success', "{$seller->fullName()}'s seller registration was rejected.");
    }

    public function approveLogistics(LogisticsProvider $logisticsProvider)
    {
        $logisticsProvider->update([
            'status' => 'approved',
            'approved_by' => Auth::guard('admin')->id(),
            'approved_at' => now(),
            'rejection_reason' => null,
        ]);

        $logisticsProvider->notify(new LogisticsRegistrationDecision('approved'));

        return back()->with('success', "{$logisticsProvider->fullName()}'s logistics registration was approved.");
    }

    public function rejectLogistics(LogisticsProvider $logisticsProvider, Request $request)
    {
        $request->validate([
            'rejection_reason' => ['required', 'string', 'max:500'],
        ]);

        $logisticsProvider->update([
            'status' => 'rejected',
            'approved_by' => Auth::guard('admin')->id(),
            'approved_at' => now(),
            'rejection_reason' => $request->rejection_reason,
        ]);

        $logisticsProvider->notify(new LogisticsRegistrationDecision('rejected', $request->rejection_reason));

        return back()->with('success', "{$logisticsProvider->fullName()}'s logistics registration was rejected.");
    }
}
