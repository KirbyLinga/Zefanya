<?php

use App\Http\Controllers\Seller\AuthController;
use App\Http\Controllers\Seller\DashboardController;
use App\Http\Controllers\Seller\MessageController;
use App\Http\Controllers\Seller\OrderController;
use App\Http\Controllers\Seller\ProductController;
use App\Http\Controllers\Seller\ShipmentController;
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

        // Product routes â€” these use the ProductController
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
        Route::patch('/products/{product}/status', [ProductController::class, 'toggleStatus'])->name('products.toggleStatus');
        Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
        Route::delete('/products/{product}/images/{image}', [ProductController::class, 'deleteImage'])
            ->name('products.images.destroy');

        Route::get('/inventory', function () {
            return to_route('seller.products.index');
        })->name('inventory.index');

        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{sellerOrder}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{sellerOrder}/accept', [OrderController::class, 'acceptOrder'])->name('orders.accept');
        Route::post('/orders/{sellerOrder}/mark-ready', [OrderController::class, 'markReady'])->name('orders.markReady');

        Route::get('/shipments', [ShipmentController::class, 'index'])->name('shipments.index');
        Route::get('/shipments/{sellerOrder}/label', [ShipmentController::class, 'label'])->name('shipments.label');

        Route::get('/vouchers', function () {
            return view('Seller.Vouchers.index');
        })->name('vouchers.index');

        Route::get('/reports', function () {
            return view('Seller.Reports.index');
        })->name('reports.index');

        Route::get('/feedback', function () {
            return view('Seller.Feedback.index');
        })->name('feedback.index');

        // Messaging routes
        Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
        // Literal /messages/unread-count MUST come before the {conversation}
        // wildcard, or Laravel matches it as a conversation id.
        Route::get('/messages/unread-count', [MessageController::class, 'unreadCount'])->name('messages.unreadCount');
        Route::get('/messages/{conversation}', [MessageController::class, 'show'])->name('messages.show');
        Route::post('/messages', [MessageController::class, 'store'])->name('messages.store');

        Route::get('/chat', function () {
            return view('Seller.Chat.index');
        })->name('chat.index');

        Route::get('/account', function () {
            return view('Seller.Account.index');
        })->name('account.index');
    });
});
