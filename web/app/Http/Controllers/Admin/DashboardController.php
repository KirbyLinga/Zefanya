<?php

namespace App\Http\Controllers\Admin;

use App\Enums\OrderStatus;
use App\Enums\ParcelIncidentStatus;
use App\Enums\ParcelReturnStatus;
use App\Http\Controllers\Controller;
use App\Models\Admin\Admin;
use App\Models\Buyer\Buyer;
use App\Models\Logistics\CodRemittance;
use App\Models\Logistics\LogisticsHub;
use App\Models\Logistics\LogisticsHubLocationChange;
use App\Models\Logistics\LogisticsProvider;
use App\Models\Logistics\LogisticsProviderLink;
use App\Models\Logistics\LogisticsRemittance;
use App\Models\Logistics\ParcelHandoffException;
use App\Models\Logistics\ParcelReturn;
use App\Models\Seller\Seller;
use App\Models\Seller\SellerPayoutRequest;
use App\Models\Seller\StoreSale;
use App\Models\Shared\Incidents;
use App\Models\Shared\SellerOrder;
use App\Models\Shared\Voucher;
use App\Support\RuntimeSchema;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();
        // Mirrors routes/admin.php, so nothing here links to a page this role cannot open.
        $canManage = in_array($admin->role, ['admin', 'super_admin'], true);
        $canModerate = $canManage || $admin->role === 'moderator';

        $registrations = $this->pendingRegistrationsByRole();
        $accounts = $this->activeAccountsByRole();
        $openDisputes = $this->whenTable('parcel_returns', fn (): int => ParcelReturn::query()->where('status', ParcelReturnStatus::InDispute->value)->count());

        return view('Admin.dashboard', [
            'admin' => $admin,
            'canManage' => $canManage,
            'canModerate' => $canModerate,
            'activeUsers' => array_sum($accounts),
            'activeBreakdown' => $this->breakdown($accounts),
            'pendingRegistrations' => array_sum($registrations),
            'openDisputes' => $openDisputes,
            'attention' => $canModerate ? $this->attention($registrations, $openDisputes, $canManage) : [],
            'money' => $canManage ? $this->platformMoney() : null,
            'settlements' => $canManage ? $this->logisticsSettlements() : collect(),
            ...$this->activity(),
        ]);
    }

    /**
     * Work waiting on an administrator. Every queue always shows, so an empty one
     * reads as done. A null url means the page is not built yet.
     *
     * @param  array<string, int>  $registrations
     * @return list<array{count: int, label: string, detail: string, icon: string, url: ?string, action: string}>
     */
    private function attention(array $registrations, int $openDisputes, bool $canManage): array
    {
        $returns = fn (Closure $scope): int => $this->whenTable('parcel_returns', fn (): int => $scope(ParcelReturn::query())->count());
        $row = fn (int $count, string $label, string $detail, string $icon, ?string $url, string $action): array => compact('count', 'label', 'detail', 'icon', 'url', 'action');

        return array_values(array_filter([
            $row(array_sum($registrations), 'Registrations to review', $this->breakdown($registrations) ?: 'Buyer, seller and logistics applications', 'user-check', route('admin.registrations.index'), 'Review'),
            $canManage ? $row($this->whenTable('seller_payout_requests', fn (): int => SellerPayoutRequest::query()->where('status', SellerPayoutRequest::PENDING)->count()),
                'Seller payout requests', 'Sellers asking to withdraw their earnings', 'wallet', route('admin.seller-payouts.index'), 'Process') : null,
            $canManage ? $row($this->whenTable('logistics_remittances', fn (): int => LogisticsRemittance::query()->where('status', LogisticsRemittance::SUBMITTED)->count()),
                'Logistics payments to confirm', 'Companies say they sent Zefanya money', 'banknote', route('admin.logistics-remittances.index'), 'Confirm') : null,
            $row($openDisputes, 'Escalated return disputes', 'Seller rejected; buyer escalated', 'scale', route('admin.returns.index'), 'Resolve'),
            $canManage ? $row($returns(fn ($query) => $query->where('financial_review_required', true)->whereNull('refunded_at')),
                'Refunds to record', 'Returned orders that were already paid', 'receipt', route('admin.returns.index'), 'Record') : null,
            $row($returns(fn ($query) => $query->whereNotNull('exception_reason')), 'Return exceptions', 'Returning parcels that are stuck', 'undo-2', route('admin.returns.index'), 'Review'),
            $row($this->whenTable('incidents', fn (): int => Incidents::query()->where('status', ParcelIncidentStatus::Open->value)->whereIn('type', ['lost', 'damaged', 'LOST', 'DAMAGED'])->count()),
                'Incident reports', 'Lost or damaged parcels', 'triangle-alert', route('admin.incidents.index'), 'Open'),
            $row($this->whenTable('logistics_hubs', fn (): int => LogisticsHub::query()->where('approval_status', 'pending')->count())
                + $this->whenTable('logistics_hub_location_changes', fn (): int => LogisticsHubLocationChange::query()->where('status', LogisticsHubLocationChange::STATUS_PENDING)->count()),
                'Hub approvals', 'New hubs, stations and location changes', 'network', route('admin.hubs.index'), 'Review'),
            $row($this->whenTable('logistics_provider_links', fn (): int => LogisticsProviderLink::query()->where('status', LogisticsProviderLink::PENDING)->count())
                + $this->whenTable('parcel_handoff_exceptions', fn (): int => ParcelHandoffException::query()->where('status', ParcelHandoffException::OPEN)->count()),
                'Partnerships and handoffs', 'Provider links and transfer problems', 'handshake', route('admin.partnerships.index'), 'Review'),
        ]));
    }

    /**
     * Order activity in Philippine days, for roles that do not see the money panel.
     *
     * @return array{ordersToday: int, ordersInProgress: int}
     */
    private function activity(): array
    {
        $settled = [OrderStatus::Delivered->value, OrderStatus::Completed->value, OrderStatus::Returned->value, OrderStatus::Cancelled->value];

        return [
            'ordersToday' => $this->whenTable('seller_orders', fn (): int => SellerOrder::query()->where('created_at', '>=', Carbon::now(StoreSale::TIMEZONE)->startOfDay()->utc())->count()),
            'ordersInProgress' => $this->whenTable('seller_orders', fn (): int => SellerOrder::query()->whereNotIn('status', $settled)->count()),
        ];
    }

    /**
     * What Zefanya earned and funded on orders finalized this Philippine month.
     * Shop vouchers are seller-funded, so only marketplace-funded ones count.
     *
     * @return array{commission: int, platformVouchers: int, shippingVouchers: int, net: int}
     */
    private function platformMoney(): array
    {
        if (! RuntimeSchema::hasTable('seller_orders')) {
            return ['commission' => 0, 'platformVouchers' => 0, 'shippingVouchers' => 0, 'net' => 0];
        }

        $finalized = fn () => SellerOrder::query()->where('commission_finalized_at', '>=', Carbon::now(StoreSale::TIMEZONE)->startOfMonth()->utc());
        $commission = (int) $finalized()->sum('commission_minor');
        $platform = (int) $finalized()->where('platform_voucher_funding_source', Voucher::FUNDING_MARKETPLACE)->sum('platform_voucher_discount_minor');
        $shipping = (int) $finalized()->where('shipping_voucher_funding_source', Voucher::FUNDING_MARKETPLACE)->sum('shipping_voucher_discount_minor');

        return ['commission' => $commission, 'platformVouchers' => $platform, 'shippingVouchers' => $shipping, 'net' => $commission - $platform - $shipping];
    }

    /**
     * COD each provider collected this Philippine month, less the shipping fees on
     * those parcels and whatever Zefanya has confirmed receiving for them.
     *
     * @return Collection<int, array{provider: string, codMinor: int, shippingMinor: int, receivedMinor: int, owedMinor: int, withRiders: int}>
     */
    private function logisticsSettlements(): Collection
    {
        if (! RuntimeSchema::hasTable('cod_remittances')) {
            return collect();
        }

        return CodRemittance::query()
            ->join('seller_orders', 'seller_orders.id', '=', 'cod_remittances.seller_order_id')
            ->where('cod_remittances.created_at', '>=', Carbon::now(StoreSale::TIMEZONE)->startOfMonth()->utc())
            ->groupBy('cod_remittances.logistics_provider_id')
            ->selectRaw('cod_remittances.logistics_provider_id, SUM(cod_remittances.amount_minor) as cod_minor, SUM(seller_orders.shipping_fee_minor) as shipping_minor')
            ->selectRaw('SUM(CASE WHEN cod_remittances.status = ? THEN 1 ELSE 0 END) as with_riders', [CodRemittance::PENDING_REMITTANCE])
            ->selectRaw('SUM(CASE WHEN cod_remittances.zefanya_received_at IS NOT NULL THEN cod_remittances.amount_minor - seller_orders.shipping_fee_minor ELSE 0 END) as received_minor')
            ->with('provider:id,business_name')
            ->get()
            ->map(fn (CodRemittance $row): array => [
                'provider' => $row->provider?->business_name ?? 'Unknown provider',
                'codMinor' => (int) $row->cod_minor,
                'shippingMinor' => (int) $row->shipping_minor,
                'receivedMinor' => (int) $row->received_minor,
                'owedMinor' => (int) $row->cod_minor - (int) $row->shipping_minor - (int) $row->received_minor,
                'withRiders' => (int) $row->with_riders,
            ])
            ->sortByDesc('owedMinor')
            ->values();
    }

    /**
     * Accounts an administrator has approved, across every role table that has
     * an approval lifecycle. Sellers and logistics providers are marketplace
     * users too, so they count alongside buyers.
     *
     * @return array<string, int>
     */
    private function activeAccountsByRole(): array
    {
        return [
            'buyer' => $this->countByStatus(Buyer::class, 'buyers', 'approved'),
            'seller' => $this->countByStatus(Seller::class, 'sellers', 'approved'),
            'logistics provider' => $this->countByStatus(LogisticsProvider::class, 'logistics_providers', 'approved'),
        ];
    }

    /**
     * Applicants awaiting an administrator's decision — mirrors the admin
     * registration queue (Admin\RegistrationController@index) exactly, so the
     * dashboard figure and the queue can never disagree.
     *
     * `unverified` (email not confirmed yet) is deliberately excluded:
     * those applicants are not yet awaiting an admin decision.
     *
     * @return array<string, int>
     */
    private function pendingRegistrationsByRole(): array
    {
        return [
            'buyer' => $this->countByStatus(Buyer::class, 'buyers', 'pending'),
            'seller' => $this->countByStatus(Seller::class, 'sellers', 'pending'),
            'logistics provider' => $this->countByStatus(LogisticsProvider::class, 'logistics_providers', 'pending'),
        ];
    }

    /** "3 buyers, 1 seller", leaving out roles with none. @param array<string, int> $counts */
    private function breakdown(array $counts): string
    {
        return collect($counts)
            ->filter()
            ->map(fn (int $count, string $role): string => $count.' '.str($role)->plural($count))
            ->implode(', ');
    }

    /**
     * Count rows in $table holding the given status — 0 when that table has not
     * been migrated on this install yet (the live site is served from this tree).
     *
     * @param  class-string<Model>  $model
     */
    private function countByStatus(string $model, string $table, string $status): int
    {
        return $this->whenTable($table, fn (): int => $model::query()->where('status', $status)->count());
    }

    /** @param Closure(): int $count */
    private function whenTable(string $table, Closure $count): int
    {
        return RuntimeSchema::hasTable($table) ? $count() : 0;
    }
}
