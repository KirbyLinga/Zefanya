<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    /**
     * Seller dashboard home.
     *
     * Product/inventory figures are real (Product model already exists and
     * is seller-scoped). Sales/order figures are placeholders — the Orders
     * module isn't built yet (see checklist §Order Management), so those
     * widgets render an honest empty state instead of fake numbers. Swap the
     * TODO block below for real queries once an `orders` table + model
     * exist; keep the same `where('seller_id', $sellerId)` scoping pattern
     * used for products.
     */
    public function index(Request $request)
    {
        $seller = Auth::guard('seller')->user();
        $sellerId = $seller->id;

        $productCount = Product::query()
            ->where('seller_id', $sellerId)
            ->count();

        $lowStockProducts = Product::query()
            ->where('seller_id', $sellerId)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->orderBy('stock_quantity')
            ->limit(5)
            ->get(['id', 'name', 'stock_quantity', 'low_stock_threshold']);

        $lowStockCount = Product::query()
            ->where('seller_id', $sellerId)
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->count();

        $activeProductCount = Product::query()
            ->where('seller_id', $sellerId)
            ->where('status', 'active')
            ->count();

        // --- TODO: wire these to the real `orders` table once it exists ---
        $totalSales = null;
        $totalOrders = null;
        $pendingOrders = null;
        $recentOrders = collect();
        $salesTrend = collect(range(6, 0))->map(fn ($daysAgo) => [
            'label' => Carbon::now()->subDays($daysAgo)->format('D'),
            'value' => 0,
        ])->values();
        // --------------------------------------------------------------------

        // --- Notifications: only query if the table exists (Notifiable trait
        //     is present on Seller, but the Laravel notifications table is not
        //     yet migrated in this project). Empty state is rendered otherwise. ---
        $notificationsTableExists = Schema::hasTable('notifications');
        $notifications = $notificationsTableExists
            ? $seller->notifications()->latest()->limit(8)->get()
            : collect();
        $unreadCount = $notificationsTableExists
            ? $seller->unreadNotifications()->count()
            : 0;
        // --------------------------------------------------------------------

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
            'ordersModuleReady' => false,
            'notifications' => $notifications,
            'unreadCount' => $unreadCount,
        ]);
    }
}
