<?php

use App\Http\Controllers\Seller\AuthController;
use App\Http\Controllers\Seller\DashboardController;
use App\Http\Controllers\Seller\ProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('seller')->name('seller.')->group(function () {
    Route::middleware('guest:seller')->group(function () {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->name('login.post');
    });

    Route::middleware(['auth:seller', 'seller.approved'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

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

        Route::get('/orders', function () {
            return view('Seller.Orders.index');
        })->name('orders.index');

        Route::get('/shipments', function () {
            return view('Seller.Shipments.index');
        })->name('shipments.index');

        Route::get('/vouchers', function () {
            return view('Seller.Vouchers.index');
        })->name('vouchers.index');

        Route::get('/reports', function () {
            return view('Seller.Reports.index');
        })->name('reports.index');

        Route::get('/feedback', function () {
            return view('Seller.Feedback.index');
        })->name('feedback.index');

        Route::get('/chat', function () {
            return view('Seller.Chat.index');
        })->name('chat.index');

        Route::get('/account', function () {
            return view('Seller.Account.index');
        })->name('account.index');
    });
});
