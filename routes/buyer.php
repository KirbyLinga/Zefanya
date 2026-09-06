<?php

use App\Http\Controllers\Buyer\{
    HomeController, CategoryController, ProductController,
    CartController, CheckoutController, OrderController,
    ChatController, AccountController
};

Route::middleware(['auth:buyer'])->prefix('buyer')->name('buyer.')->group(function () {
    Route::get('/home', [HomeController::class, 'index'])->name('home');

    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('/categories/{category}', [CategoryController::class, 'show'])->name('categories.show');

    Route::get('/products', [ProductController::class, 'index'])->name('products.index'); // ?q= and ?category= both land here
    Route::get('/products/{product}', [ProductController::class, 'show'])->name('products.show');

    Route::get('/cart', [CartController::class, 'index'])->name('cart.index');

    Route::get('/checkout', [CheckoutController::class, 'index'])->name('checkout.index');
    Route::get('/checkout/success', [CheckoutController::class, 'success'])->name('checkout.success');

    Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
    Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');

    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{thread}', [ChatController::class, 'show'])->name('chat.show');

    Route::get('/account', [AccountController::class, 'index'])->name('account.index');
    Route::get('/account/profile', [AccountController::class, 'profile'])->name('account.profile');
    Route::get('/account/address', [AccountController::class, 'address'])->name('account.address');
    Route::get('/account/password', [AccountController::class, 'password'])->name('account.password');
});
