<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Seller\Seller;
use App\Services\Admin\BuyerRegistrationDecisionService;
use App\Services\Admin\LogisticsRegistrationDecisionService;
use App\Services\Admin\SellerRegistrationDecisionService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RegistrationController extends Controller
{
    public function __construct(
        private SellerRegistrationDecisionService $sellerDecisions,
        private BuyerRegistrationDecisionService $buyerDecisions,
        private LogisticsRegistrationDecisionService $logisticsDecisions,
    ) {}

    /**
     * Registration management table view with filtering, search, and pagination.
     *
     * Query params:
     *   ?search=<text>                   free-text search (name, email, contact).
     *   ?type=buyer|seller|logistics     filter by account type.
     *   ?status=pending|approved|rejected|suspended  (default: pending).
     *   ?date_range=7|30|90|0            days window; **0 / omitted = all time**
     *                                    (pending defaults to all-time so old
     *                                    applications stay visible with the stat card).
     *   ?per_page=10|25|50               pagination size (default: 25).
     */
    public function index(Request $request)
    {
        if (! Schema::hasTable('buyers') || ! Schema::hasTable('sellers')) {
            abort(404, 'Registration tables haven\'t been created yet — this feature isn\'t available.');
        }

        $searchQuery = mb_substr(trim((string) $request->query('search')), 0, 100);
        $accountType = (string) $request->query('type', '');
        $statusFilter = (string) $request->query('status', 'pending');
        // All-time by default — a pending application older than 7 days must
        // still appear when the pending filter (and pending stat card) is shown.
        $dateRange = (int) $request->query('date_range', 0);
        $perPage = (int) $request->query('per_page', 25);

        if (! in_array($perPage, [10, 25, 50], true)) {
            $perPage = 25;
        }

        if (! in_array($accountType, ['', 'buyer', 'seller', 'logistics'], true)) {
            $accountType = '';
        }

        $validStatuses = ['pending', 'approved', 'rejected', 'suspended'];
        if (! in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = 'pending';
        }

        if (! in_array($dateRange, [0, 7, 30, 90], true)) {
            $dateRange = 0;
        }

        $pendingCount = $this->countRegistrations(status: 'pending');
        $approvedCount = $this->countRegistrations(status: 'approved');
        $rejectedCount = $this->countRegistrations(status: 'rejected');

        $pendingTrend = $this->calculateTrend(status: 'pending');
        $approvedTrend = $this->calculateTrend(status: 'approved');
        $rejectedTrend = $this->calculateTrend(status: 'rejected');

        $registrations = $this->paginateFilteredRegistrations(
            $searchQuery,
            $accountType,
            $statusFilter,
            $dateRange,
            $perPage,
            max(1, (int) $request->query('page', 1)),
            $request->query(),
        );

        return view('Admin.Registrations.index', [
            'registrations' => $registrations,
            'pendingCount' => $pendingCount,
            'approvedCount' => $approvedCount,
            'rejectedCount' => $rejectedCount,
            'pendingTrend' => $pendingTrend,
            'approvedTrend' => $approvedTrend,
            'rejectedTrend' => $rejectedTrend,
            'searchQuery' => $searchQuery,
            'accountType' => $accountType,
            'status' => $statusFilter,
            'dateRange' => $dateRange,
            'perPage' => $perPage,
        ]);
    }

    private function countRegistrations(string $status): int
    {
        if (! Schema::hasTable('logistics_providers')) {
            return Buyer::where('status', $status)->count()
                + Seller::where('status', $status)->count();
        }

        return Buyer::where('status', $status)->count()
            + Seller::where('status', $status)->count()
            + LogisticsProvider::where('status', $status)->count();
    }

    private function calculateTrend(string $status): int
    {
        $now = now();
        $last7Start = $now->copy()->subDays(7);
        $last7End = $now->copy();
        $prev7Start = $now->copy()->subDays(14);
        $prev7End = $now->copy()->subDays(7);

        $current = $this->countRegistrationsByDateRange($status, $last7Start, $last7End);
        $previous = $this->countRegistrationsByDateRange($status, $prev7Start, $prev7End);

        if ($previous == 0) {
            return $current > 0 ? 100 : 0;
        }

        return (int) (($current - $previous) / $previous * 100);
    }

    private function countRegistrationsByDateRange(string $status, $startDate, $endDate): int
    {
        $count = Buyer::where('status', $status)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        $count += Seller::where('status', $status)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->count();

        if (Schema::hasTable('logistics_providers')) {
            $count += LogisticsProvider::where('status', $status)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count();
        }

        return $count;
    }

    /**
     * UNION ALL across the three registries, then paginate in SQL so the
     * queue does not load every matching row into memory.
     */
    private function paginateFilteredRegistrations(
        string $search,
        string $type,
        string $status,
        int $dateRange,
        int $perPage,
        int $page,
        array $query,
    ): LengthAwarePaginator {
        $parts = [];

        if ($type === '' || $type === 'buyer') {
            $parts[] = $this->registrySelect('buyers', 'buyer', $status, $search, $dateRange, withBusinessName: false);
        }
        if ($type === '' || $type === 'seller') {
            $parts[] = $this->registrySelect('sellers', 'seller', $status, $search, $dateRange, withBusinessName: true);
        }
        if (($type === '' || $type === 'logistics') && Schema::hasTable('logistics_providers')) {
            $parts[] = $this->registrySelect('logistics_providers', 'logistics', $status, $search, $dateRange, withBusinessName: true);
        }

        if ($parts === []) {
            return new LengthAwarePaginator([], 0, $perPage, $page, [
                'path' => route('admin.registrations.index'),
                'query' => $query,
            ]);
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        $sql = '('.$union->toSql().') as registrations';

        $total = (int) DB::table(DB::raw($sql))
            ->mergeBindings($union)
            ->count();

        $rows = DB::table(DB::raw($sql))
            ->mergeBindings($union)
            ->orderByDesc('created_at')
            ->forPage($page, $perPage)
            ->get();

        $items = $this->hydrateRegistrationRows($rows);

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => route('admin.registrations.index'),
            'query' => $query,
        ]);
    }

    private function registrySelect(
        string $table,
        string $accountType,
        string $status,
        string $search,
        int $dateRange,
        bool $withBusinessName,
    ): QueryBuilder {
        // Literal (not a bound ?) — unionAll + fromSub reorders bindings so a
        // selectRaw placeholder for account_type steals the status/search params.
        // $accountType is always from the buyer|seller|logistics allowlist.
        if (! in_array($accountType, ['buyer', 'seller', 'logistics'], true)) {
            throw new \InvalidArgumentException('Invalid account type ['.$accountType.'].');
        }

        $query = DB::table($table)
            ->selectRaw(
                "'{$accountType}' as account_type, id, first_name, last_name, email, contact_no, created_at, status, street, house_number, municipality_name, province_name, barangay_name, address_detail, postal_code, upload_id_path, ".
                ($withBusinessName ? 'business_name' : 'NULL as business_name')
            )
            ->whereNull('archived_at')
            ->where('status', $status);

        if ($dateRange > 0) {
            $query->where('created_at', '>=', now()->subDays($dateRange));
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (QueryBuilder $q) use ($like, $withBusinessName): void {
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

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    private function hydrateRegistrationRows(Collection $rows): Collection
    {
        $idsByType = $rows->groupBy('account_type');

        $buyers = Buyer::query()
            ->whereIn('id', $idsByType->get('buyer', collect())->pluck('id'))
            ->get()
            ->keyBy('id');
        $sellers = Seller::query()
            ->whereIn('id', $idsByType->get('seller', collect())->pluck('id'))
            ->get()
            ->keyBy('id');
        $logistics = LogisticsProvider::query()
            ->whereIn('id', $idsByType->get('logistics', collect())->pluck('id'))
            ->get()
            ->keyBy('id');

        return $rows->map(function (object $row) use ($buyers, $sellers, $logistics) {
            $model = match ($row->account_type) {
                'buyer' => $buyers->get($row->id),
                'seller' => $sellers->get($row->id),
                'logistics' => $logistics->get($row->id),
                default => null,
            };

            if ($model === null) {
                return null;
            }

            $status = $model->status instanceof \BackedEnum ? $model->status->value : $model->status;

            return (object) [
                'type' => $row->account_type,
                'model' => $model,
                'id' => $model->id,
                'first_name' => $model->first_name,
                'last_name' => $model->last_name,
                'email' => $model->email,
                'contact_no' => $model->contact_no,
                'created_at' => $model->created_at,
                'status' => $status,
            ];
        })->filter()->values();
    }

    public function approveBuyer(Buyer $buyer)
    {
        if (! $this->buyerDecisions->decide($buyer, Auth::guard('admin')->user(), 'approved')) {
            return back()->with('error', 'Only pending registrations can be approved.');
        }

        return back()->with('success', "{$buyer->fullName()}'s buyer registration was approved.");
    }

    public function rejectBuyer(Buyer $buyer, Request $request)
    {
        if (! $this->buyerDecisions->decide($buyer, Auth::guard('admin')->user(), 'rejected', $request->input('rejection_reason'))) {
            return back()->with('error', 'Only pending registrations can be rejected.');
        }

        return back()->with('success', "{$buyer->fullName()}'s buyer registration was rejected.");
    }

    public function approveSeller(Seller $seller)
    {
        if (! $this->sellerDecisions->decide($seller, Auth::guard('admin')->user(), 'approved')) {
            return back()->with('error', 'Only pending registrations can be approved.');
        }

        return back()->with('success', "{$seller->fullName()}'s seller registration was approved.");
    }

    public function rejectSeller(Seller $seller, Request $request)
    {
        if (! $this->sellerDecisions->decide($seller, Auth::guard('admin')->user(), 'rejected', $request->input('rejection_reason'))) {
            return back()->with('error', 'Only pending registrations can be rejected.');
        }

        return back()->with('success', "{$seller->fullName()}'s seller registration was rejected.");
    }

    public function approveLogistics(LogisticsProvider $logisticsProvider)
    {
        try {
            if (! $this->logisticsDecisions->decide($logisticsProvider, Auth::guard('admin')->user(), 'approved')) {
                return back()->with('error', 'Only pending registrations can be approved.');
            }
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', "{$logisticsProvider->fullName()}'s logistics registration was approved. Province {$logisticsProvider->province_name} is now verified.");
    }

    public function rejectLogistics(LogisticsProvider $logisticsProvider, Request $request)
    {
        if (! $this->logisticsDecisions->decide($logisticsProvider, Auth::guard('admin')->user(), 'rejected', $request->input('rejection_reason'))) {
            return back()->with('error', 'Only pending registrations can be rejected.');
        }

        return back()->with('success', "{$logisticsProvider->fullName()}'s logistics registration was rejected.");
    }
}
