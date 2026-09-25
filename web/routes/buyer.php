<?php

use App\Http\Controllers\Buyer\AccountController;
use App\Http\Controllers\Buyer\CartController;
use App\Http\Controllers\Buyer\CategoryController;
use App\Http\Controllers\Buyer\ChatController;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Http\Controllers\Buyer\HomeController;
use App\Http\Controllers\Buyer\LoginController;
use App\Http\Controllers\Buyer\OrderController;
use App\Http\Controllers\Buyer\ProductController;

/*
|--------------------------------------------------------------------------
| Buyer Routes
|--------------------------------------------------------------------------
| Guest-accessible browsing (catalog) is open to everyone.
| Identity-specific pages (cart, checkout, orders, account, chat) require auth:buyer.
| Login/registration routes must stay outside auth:buyer so guests can reach them.
*/

// ── Guest-accessible browsing ──────────────────────────────
Route::prefix('buyer')->name('buyer.')->group(function () {

    // Auth entry points (must stay outside auth:buyer)
    Route::middleware('guest:buyer')->group(function () {
        Route::get('/login', [LoginController::class, 'show'])->name('login');
        Route::post('/login', [LoginController::class, 'login'])
            ->name('login.submit')
            ->middleware('throttle:5,1');
    });

    // Catalog browsing — open to guests AND logged-in buyers
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::get('/home', [HomeController::class, 'index']);
    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('/categories/{slug}', [CategoryController::class, 'show'])->name('categories.show');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{id}', [ProductController::class, 'show'])->name('products.show');

    // ── Login required from here down ──────────────────────
    Route::middleware('auth:buyer')->group(function () {
        Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

        Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
        Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
        Route::get('/cart/summary', [CartController::class, 'summary'])->name('cart.summary');

        // NOTE: the literal /cart/voucher routes MUST be registered before the
        // DELETE /cart/{id} wildcard below, otherwise Laravel's registration-order
        // matching sends "DELETE /buyer/cart/voucher" to CartController@destroy
        // with $id = "voucher" (a TypeError, not the voucher endpoint).
        Route::post('/cart/voucher', [CartController::class, 'applyVoucher'])->name('cart.voucher.apply');
        Route::delete('/cart/voucher', [CartController::class, 'removeVoucher'])->name('cart.voucher.remove');

        Route::delete('/cart', [CartController::class, 'bulkDestroy'])->name('cart.bulk-destroy');
        Route::patch('/cart/{id}', [CartController::class, 'update'])->name('cart.update');
        Route::delete('/cart/{id}', [CartController::class, 'destroy'])->name('cart.destroy');

        Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
        Route::post('/checkout', [CheckoutController::class, 'store'])->name('checkout.store');
        Route::get('/checkout/success/{order}', [CheckoutController::class, 'success'])->name('checkout.success');

        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');

        Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
        Route::get('/chat/{id}', [ChatController::class, 'show'])->name('chat.show');

        Route::get('/account', [AccountController::class, 'index'])->name('account.index');
        Route::patch('/account', [AccountController::class, 'update'])->name('account.update');
    });
});
