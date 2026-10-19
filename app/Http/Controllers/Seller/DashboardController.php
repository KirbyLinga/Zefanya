<?php

namespace App\Http\Controllers\Seller;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SellerOrder;
use Illuminate\Support\Carbon;
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

        // ── Real order/sales figures (orders module is now live) ──────────
        $baseQuery = SellerOrder::query()
            ->where('seller_id', $sellerId)
            ->with(['order' => fn ($q) => $q->with('buyer')]);

        $totalOrders = (int) $baseQuery->count();

        $pendingOrders = (int) $baseQuery
            ->whereIn('status', [
                OrderStatus::Placed->value,
                OrderStatus::Confirmed->value,
                OrderStatus::Preparing->value,
            ])->count();

        // Sales = sum of fully delivered/completed subtotals (centavos → float).
        $deliveredSalesMinor = (int) $baseQuery
            ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::Completed->value])
            ->sum('subtotal_minor');
        $totalSales = $deliveredSalesMinor > 0 ? $deliveredSalesMinor / 100 : null;

        $recentOrders = $baseQuery
            ->latest('id')
            ->limit(8)
            ->get();

        $salesTrend = collect(range(6, 0))->map(function (int $daysAgo) use ($baseQuery): array {
            $day = Carbon::now()->subDays($daysAgo)->startOfDay();
            $value = $baseQuery
                ->whereIn('status', [OrderStatus::Delivered->value, OrderStatus::Completed->value])
                ->whereDate('seller_orders.updated_at', $day->toDateString())
                ->sum('subtotal_minor');

            return [
                'label' => Carbon::now()->subDays($daysAgo)->format('D'),
                'value' => $value,
            ];
        })->values();

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
            'productCount' => $productCount,
            'activeProductCount' => $activeProductCount,
            'lowStockCount' => $lowStockCount,
            'lowStockProducts' => $lowStockProducts,
            'recentOrders' => $recentOrders,
            'salesTrend' => $salesTrend,
            'ordersModuleReady' => true,
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }
}
