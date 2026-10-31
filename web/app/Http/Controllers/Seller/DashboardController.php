<?php

namespace App\Http\Controllers\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Catalog\Product;
use App\Models\Shared\SellerOrder;
use App\Services\Seller\SellerSalesTrend;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    /**
     * Seller dashboard home.
     *
     * Product/inventory figures are real. Order/sales figures are now derived
     * from seller_orders (the orders module is live), so the previous null
     * placeholders are replaced with real counts and a recent-orders table.
     */
    public function index()
    {
        $seller = Auth::guard('seller')->user();
        $sellerId = $seller->id;

        $productCount = Product::query()
            ->where('seller_id', $sellerId)
            ->count();

        // Low stock is a per-variant concept: a product is low/out when its
        // stock rollup (SUM of variant stock) is at/below the threshold.
        $lowStockProducts = Product::query()
            ->where('seller_id', $sellerId)
            ->whereHas('variants', fn ($q) => $q
                ->select('product_id')
                ->groupBy('product_id')
                ->havingRaw('SUM(stock_quantity) <= products.low_stock_threshold'))
            ->withSum('variants as variants_stock_total', 'stock_quantity')
            ->orderBy('variants_stock_total')
            ->limit(5)
            ->get(['id', 'name', 'low_stock_threshold']);

        $lowStockCount = Product::query()
            ->where('seller_id', $sellerId)
            ->whereHas('variants', fn ($q) => $q
                ->select('product_id')
                ->groupBy('product_id')
                ->havingRaw('SUM(stock_quantity) <= products.low_stock_threshold'))
            ->count();

        $activeProductCount = Product::query()
            ->where('seller_id', $sellerId)
            ->where('status', 'active')
            ->count();

        $productsMissingWeight = Product::query()
            ->where('seller_id', $sellerId)
            ->where('weight_grams', '<=', 0)
            ->count();

        // ── Real order/sales figures (orders module is now live) ──────────
        $baseQuery = SellerOrder::query()
            ->where('seller_id', $sellerId)
            ->with(['order' => fn ($q) => $q->with('buyer')]);

        $totalOrders = (int) $baseQuery->count();

        $pendingOrders = (int) (clone $baseQuery)
            ->whereIn('status', [
                OrderStatus::Placed->value,
                OrderStatus::Confirmed->value,
                OrderStatus::Preparing->value,
            ])->count();

        // Sales = sum of fully delivered/completed subtotals (centavos → float).
        $deliveredSalesMinor = (int) (clone $baseQuery)
            ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::Completed->value])
            ->sum('subtotal_minor');
        $totalSales = $deliveredSalesMinor > 0 ? $deliveredSalesMinor / 100 : null;

        $completedQuery = (clone $baseQuery)->where('status', OrderStatus::Completed->value);
        $completedOrders = (int) (clone $completedQuery)->count();

        // Use the recorded net once commission and voucher funding are finalized.
        // Never estimate legacy/unknown earnings from gross sales or payout cash.
        $finalizedEarnings = (clone $completedQuery)
            ->whereNotNull('commission_finalized_at')
            ->whereNotNull('commission_bps')
            ->whereNotNull('commission_minor')
            ->whereNotNull('seller_net_minor');
        $netEarningsMinor = (int) (clone $finalizedEarnings)->sum('seller_net_minor');
        $earningsPendingCount = $completedOrders - (int) (clone $finalizedEarnings)->count();

        $recentOrders = (clone $baseQuery)
            ->latest('id')
            ->limit(8)
            ->get();

        $salesSummary = app(SellerSalesTrend::class)->forSeller($sellerId);

        // ── Notifications: only query if the table exists (Notifiable trait
        //     is present on Seller, but the Laravel notifications table may not
        //     be migrated in this project yet). Empty state is rendered otherwise.
        $notificationsTableExists = Schema::hasTable('notifications');
        $notifications = $notificationsTableExists
            ? $seller->notifications()->latest()->limit(8)->get()
            : collect();
        $unreadCount = $notificationsTableExists
            ? $seller->unreadNotifications()->count()
            : 0;

        return view('Seller.Dashboard.index', [
            'seller' => $seller,
            'totalSales' => $totalSales,
            'totalOrders' => $totalOrders,
            'pendingOrders' => $pendingOrders,
            'completedOrders' => $completedOrders,
            'netEarningsMinor' => $netEarningsMinor,
            'earningsPendingCount' => $earningsPendingCount,
            'productCount' => $productCount,
            'activeProductCount' => $activeProductCount,
            'productsMissingWeight' => $productsMissingWeight,
            'lowStockCount' => $lowStockCount,
            'lowStockProducts' => $lowStockProducts,
            'recentOrders' => $recentOrders,
            'salesTrend' => $salesSummary['days'],
            'salesSummary' => $salesSummary,
            'ordersModuleReady' => true,
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }
}
