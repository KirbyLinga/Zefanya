<?php

use App\Http\Controllers\Seller\AccountController;
use App\Http\Controllers\Seller\AuthController;
use App\Http\Controllers\Seller\DashboardController;
use App\Http\Controllers\Seller\EarningsController;
use App\Http\Controllers\Seller\FeedbackController;
use App\Http\Controllers\Seller\MessageController;
use App\Http\Controllers\Seller\NotificationController;
use App\Http\Controllers\Seller\OrderController;
use App\Http\Controllers\Seller\PayoutRequestController;
use App\Http\Controllers\Seller\ProductController;
use App\Http\Controllers\Seller\PromotionController;
use App\Http\Controllers\Seller\ReportController;
use App\Http\Controllers\Seller\ReturnController;
use App\Http\Controllers\Seller\ShipmentController;
use App\Http\Controllers\Seller\ShippingProviderController;
use App\Http\Controllers\Seller\StoreCategoryController;
use App\Http\Controllers\Seller\StoreSaleController;
use App\Http\Controllers\Seller\VoucherController;
use Illuminate\Support\Facades\Route;

Route::prefix('seller')->name('seller.')->group(function () {
    Route::middleware('guest.role')->group(function () {
        Route::post('/login', [AuthController::class, 'login'])
            ->name('login.post')
            ->middleware('throttle:login');
    });

    Route::middleware(['auth:seller', 'seller.approved'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('throttle:logout');

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])
            ->whereUuid('notification')->name('notifications.read');

        Route::get('/earnings', [EarningsController::class, 'index'])->name('earnings.index');

        Route::get('/payout-requests', [PayoutRequestController::class, 'index'])->name('payout-requests.index');
        Route::post('/payout-requests', [PayoutRequestController::class, 'store'])->name('payout-requests.store');
        Route::delete('/payout-requests/{payoutRequest}', [PayoutRequestController::class, 'destroy'])
            ->whereNumber('payoutRequest')->name('payout-requests.destroy');

        Route::get('/store-categories', [StoreCategoryController::class, 'index'])->name('store-categories.index');
        Route::post('/store-categories', [StoreCategoryController::class, 'store'])->name('store-categories.store');
        Route::post('/store-categories/reorder', [StoreCategoryController::class, 'reorder'])->name('store-categories.reorder');
        Route::post('/store-categories/{storeCategory}/move', [StoreCategoryController::class, 'move'])->name('store-categories.move');
        Route::patch('/store-categories/{storeCategory}/visibility', [StoreCategoryController::class, 'visibility'])->name('store-categories.visibility');
        Route::get('/store-categories/{storeCategory}/delete', [StoreCategoryController::class, 'deleteConfirmation'])->name('store-categories.delete-confirmation');
        Route::delete('/store-categories/{storeCategory}', [StoreCategoryController::class, 'destroy'])->name('store-categories.destroy');
        Route::patch('/store-categories/{storeCategory}', [StoreCategoryController::class, 'update'])->name('store-categories.update');
        Route::patch('/products/{product}/store-categories', [StoreCategoryController::class, 'assign'])->name('store-categories.assign');

        // Product routes â€” these use the ProductController
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::post('/products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');
        Route::post('/products/{product}/duplicate', [ProductController::class, 'duplicate'])->name('products.duplicate');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::patch('/products/{product}/status', [ProductController::class, 'toggleStatus'])->name('products.toggleStatus');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::delete('/products/{product}/images/{image}', [ProductController::class, 'deleteImage'])
            ->name('products.images.destroy');

        Route::get('/inventory', function () {
            return to_route('seller.products.index');
        })->name('inventory.index');

        Route::get('/returns', [ReturnController::class, 'index'])->name('returns.index');
        Route::post('/returns/{return}/approve', [ReturnController::class, 'approve'])->name('returns.approve');
        Route::post('/returns/{return}/reject', [ReturnController::class, 'reject'])->name('returns.reject');
        Route::get('/returns/{return}/photos/{index}', [ReturnController::class, 'photo'])->whereNumber('index')->name('returns.photo');
        Route::post('/returns/{return}/verify', [ReturnController::class, 'verify'])->name('returns.verify');
        Route::post('/returns/{return}/receive', [ReturnController::class, 'receive'])->name('returns.receive');
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{sellerOrder}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{sellerOrder}/accept', [OrderController::class, 'acceptOrder'])->name('orders.accept');
        Route::post('/orders/{sellerOrder}/pack', [OrderController::class, 'packOrder'])->name('orders.pack');
        Route::post('/orders/{sellerOrder}/decline', [OrderController::class, 'declineOrder'])->name('orders.decline');
        Route::post('/orders/{sellerOrder}/mark-ready', [OrderController::class, 'markReady'])->name('orders.markReady');

        Route::get('/shipments', [ShipmentController::class, 'index'])->name('shipments.index');
        Route::get('/shipments/{sellerOrder}/label', [ShipmentController::class, 'label'])->name('shipments.label');

        Route::get('/shipping-providers', [ShippingProviderController::class, 'index'])->name('shipping-providers.index');
        Route::post('/shipping-providers/{logistics_provider}/enable', [ShippingProviderController::class, 'enable'])
            ->name('shipping-providers.enable');
        Route::post('/shipping-providers/{logistics_provider}/disable', [ShippingProviderController::class, 'disable'])
            ->name('shipping-providers.disable');

        Route::get('/promotions', [PromotionController::class, 'index'])->name('promotions.index');
        Route::post('/promotions', [PromotionController::class, 'store'])->name('promotions.store');
        Route::delete('/promotions/{promotion}', [PromotionController::class, 'destroy'])->name('promotions.destroy');

        Route::get('/store-sales', [StoreSaleController::class, 'index'])->name('store-sales.index');
        Route::get('/store-sales/create', [StoreSaleController::class, 'create'])->name('store-sales.create');
        Route::post('/store-sales', [StoreSaleController::class, 'store'])->name('store-sales.store');
        Route::get('/store-sales/{storeSale}/edit', [StoreSaleController::class, 'edit'])->name('store-sales.edit');
        Route::put('/store-sales/{storeSale}', [StoreSaleController::class, 'update'])->name('store-sales.update');
        Route::post('/store-sales/{storeSale}/end', [StoreSaleController::class, 'end'])->name('store-sales.end');
        Route::delete('/store-sales/{storeSale}', [StoreSaleController::class, 'destroy'])->name('store-sales.destroy');

        Route::get('/vouchers', [VoucherController::class, 'index'])->name('vouchers.index');
        Route::get('/vouchers/create', [VoucherController::class, 'create'])->name('vouchers.create');
        Route::post('/vouchers', [VoucherController::class, 'store'])->name('vouchers.store');
        Route::get('/vouchers/{voucher}/edit', [VoucherController::class, 'edit'])->name('vouchers.edit');
        Route::put('/vouchers/{voucher}', [VoucherController::class, 'update'])->name('vouchers.update');
        Route::post('/vouchers/{voucher}/end', [VoucherController::class, 'end'])->name('vouchers.end');
        Route::delete('/vouchers/{voucher}', [VoucherController::class, 'destroy'])->name('vouchers.destroy');

        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');

        Route::get('/feedback', [FeedbackController::class, 'index'])->name('feedback.index');
        Route::post('/reviews/{review}/reply', [FeedbackController::class, 'reply'])->name('feedback.reply');
        Route::patch('/reviews/{review}/reply', [FeedbackController::class, 'updateReply'])->name('feedback.reply.update');

        // Messaging routes
        Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
        // Literal /messages/unread-count MUST come before the {conversation}
        // wildcard, or Laravel matches it as a conversation id.
        Route::get('/messages/unread-count', [MessageController::class, 'unreadCount'])->name('messages.unreadCount');
        Route::get('/messages/{conversation}', [MessageController::class, 'show'])->name('messages.show');
        Route::get('/messages/{conversation}/images/{message}', [MessageController::class, 'image'])->name('messages.image');
        Route::post('/messages', [MessageController::class, 'store'])->name('messages.store');
        Route::post('/orders/{sellerOrder}/message', [MessageController::class, 'startFromOrder'])->name('orders.message');

        Route::get('/account', [AccountController::class, 'index'])->name('account.index');
        Route::patch('/account', [AccountController::class, 'update'])->name('account.update');
        Route::patch('/account/vacation', [AccountController::class, 'vacation'])->name('account.vacation');
    });
});
