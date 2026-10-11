<?php

use App\Http\Controllers\Buyer\AccountController;
use App\Http\Controllers\Buyer\CartController;
use App\Http\Controllers\Buyer\CategoryController;
use App\Http\Controllers\Buyer\ChatController;
use App\Http\Controllers\Buyer\CheckoutController;
use App\Http\Controllers\Buyer\HelpController;
use App\Http\Controllers\Buyer\HomeController;
use App\Http\Controllers\Buyer\LoginController;
use App\Http\Controllers\Buyer\MessageController;
use App\Http\Controllers\Buyer\NotificationController;
use App\Http\Controllers\Buyer\OrderController;
use App\Http\Controllers\Buyer\PageController;
use App\Http\Controllers\Buyer\ProductController;
use App\Http\Controllers\Buyer\SearchController;
use App\Http\Controllers\Buyer\SettingsController;
use App\Http\Controllers\Buyer\WishlistController;

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
    Route::get('/categories/{id}', [CategoryController::class, 'show'])->name('categories.show');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{id}', [ProductController::class, 'show'])->name('products.show');
    Route::get('/search', [SearchController::class, 'index'])->name('search.index');
    Route::get('/search/suggestions', [SearchController::class, 'suggestions'])->name('search.suggestions');
    Route::get('/pages/shipping-returns', [PageController::class, 'shippingReturns'])->name('pages.shipping-returns');
    Route::get('/pages/privacy-policy', [PageController::class, 'privacyPolicy'])->name('pages.privacy-policy');
    Route::get('/pages/terms-of-service', [PageController::class, 'termsOfService'])->name('pages.terms-of-service');
    Route::get('/pages/about', [PageController::class, 'about'])->name('pages.about');
    Route::get('/pages/contact', [PageController::class, 'contact'])->name('pages.contact');

    // ── Login required from here down ──────────────────────
    Route::middleware('auth:buyer')->group(function () {
        Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

        Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
        Route::post('/cart/add', [CartController::class, 'add'])->name('cart.add');
        Route::get('/cart/summary', [CartController::class, 'summary'])->name('cart.summary');
        Route::get('/cart/mini', [CartController::class, 'miniCart'])->name('cart.mini');

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
        Route::post('/checkout/buy-now', [CartController::class, 'buyNow'])->name('checkout.buy-now');

        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order}/confirm-received', [OrderController::class, 'confirmReceived'])->name('orders.confirmReceived');

        Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
        Route::get('/chat/{id}', [ChatController::class, 'show'])->name('chat.show');

        Route::get('/account', [AccountController::class, 'index'])->name('account.index');
        Route::patch('/account', [AccountController::class, 'update'])->name('account.update');

        Route::get('/account/address', [AccountController::class, 'addressEdit'])->name('account.address');
        Route::patch('/account/address', [AccountController::class, 'addressUpdate'])->name('account.address.update');

        Route::get('/account/password', [AccountController::class, 'passwordEdit'])->name('account.password');
        Route::patch('/account/password', [AccountController::class, 'passwordUpdate'])->name('account.password.update');

        Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
        Route::post('/wishlist/{productId}', [WishlistController::class, 'toggle'])->name('wishlist.toggle');
        Route::delete('/wishlist/{id}', [WishlistController::class, 'destroy'])->name('wishlist.destroy');

        Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('/settings/delete-account', [SettingsController::class, 'deleteAccount'])->name('settings.deleteAccount');

        Route::get('/help', [HelpController::class, 'index'])->name('help.index');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::get('/notifications/count', [NotificationController::class, 'count'])->name('notifications.count');
        Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.markRead');
        Route::post('/notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.markAllRead');

        Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
        Route::get('/messages/{conversation}', [MessageController::class, 'show'])->name('messages.show');
        Route::post('/messages', [MessageController::class, 'store'])->name('messages.store');
        Route::post('/messages/get-or-create', [MessageController::class, 'getOrCreateConversation'])->name('messages.getOrCreate');
        Route::get('/messages/unread-count', [MessageController::class, 'unreadCount'])->name('messages.unreadCount');
    });
});
