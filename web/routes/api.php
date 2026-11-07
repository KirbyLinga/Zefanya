<?php

use App\Http\Controllers\Api\Buyer\AddressController;
use App\Http\Controllers\Api\Buyer\CartController;
use App\Http\Controllers\Api\Buyer\CatalogController;
use App\Http\Controllers\Api\Buyer\CheckoutController;
use App\Http\Controllers\Api\Buyer\LoginController;
use App\Http\Controllers\Api\Buyer\LogoutController;
use App\Http\Controllers\Api\Buyer\MessageController;
use App\Http\Controllers\Api\Buyer\NotificationController;
use App\Http\Controllers\Api\Buyer\OrderActionController;
use App\Http\Controllers\Api\Buyer\OrderController;
use App\Http\Controllers\Api\Buyer\ProfileController;
use App\Http\Controllers\Api\Buyer\WishlistController;
use App\Http\Controllers\Api\Rider\IncidentController as RiderIncidentController;
use App\Http\Controllers\Api\Rider\JobController as RiderJobController;
use App\Http\Controllers\Api\Rider\LoginController as RiderLoginController;
use App\Http\Controllers\Api\Rider\LogoutController as RiderLogoutController;
use App\Http\Controllers\Api\Rider\NotificationController as RiderNotificationController;
use App\Http\Controllers\Api\Rider\ParcelActionController as RiderParcelActionController;
use App\Http\Controllers\Api\Rider\ProfileController as RiderProfileController;
use App\Http\Controllers\Api\Rider\ProviderController as RiderProviderController;
use App\Http\Controllers\Api\Rider\TokenController as RiderTokenController;
use App\Http\Controllers\Rider\ParcelIncidentController;
use App\Http\Controllers\Rider\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::post('/v1/buyer/checkout/quote', \App\Http\Controllers\Api\Buyer\CheckoutPreviewController::class)
    ->middleware(['auth:sanctum', 'api.role:buyer', 'throttle:20,1'])->name('api.v1.buyer.checkout.quote');

/* --------------------------------------------------------------------------
 * Buyer API
 * --------------------------------------------------------------------------
 * Bearer-token auth only. Login and registration/OTP live OUTSIDE this group
 * (guests authenticate without a token). Everything else under /api/buyer/
 * requires a valid, non-expired, non-revoked bearer token with the "buyer"
 * ability; the framework's sanctum guard resolves the token and ApiRole then
 * asserts the token has the buyer ability.
 * -------------------------------------------------------------------------- */

// Guests authenticate with these routes; NO token required.
Route::prefix('buyer')->name('api.buyer.')->group(function (): void {
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:api-login')->name('login');

    // Public catalog (guests may browse, as on the web). Prices are server-resolved.
    Route::middleware('throttle:120,1')->group(function (): void {
        Route::get('/categories', [CatalogController::class, 'categories'])->name('categories');
        Route::get('/products', [CatalogController::class, 'products'])->name('products.index');
        Route::get('/products/{product}', [CatalogController::class, 'show'])->whereNumber('product')->name('products.show');
    });
});

// Token-bearing routes.
Route::prefix('buyer')->name('api.buyer.')->group(function (): void {
    Route::middleware(['auth:sanctum', 'api.role:buyer'])->group(function (): void {
        Route::get('/profile', [ProfileController::class, 'index'])->name('profile');
        Route::post('/logout', [LogoutController::class, 'store'])->name('logout');

        // Per-seller shipments (tracking) and receipt confirmation via BuyerShipmentConfirmation.
        Route::get('/shipments', [OrderController::class, 'index'])->middleware('throttle:60,1')->name('shipments.index');
        Route::get('/shipments/{shipment}', [OrderController::class, 'show'])->middleware('throttle:60,1')->name('shipments.show');
        Route::post('/orders/{order}/shipments/{shipment}/confirm-received', [OrderController::class, 'confirm'])
            ->middleware('throttle:20,1')->name('shipments.confirm');

        Route::middleware('throttle:60,1')->group(function (): void {
            Route::get('/reviews/mine', [\App\Http\Controllers\Api\Buyer\ReviewController::class, 'index'])->name('reviews.mine');

            // Addresses (BuyerAddressBook).
            Route::get('/addresses', [AddressController::class, 'index'])->name('addresses.index');
            Route::post('/addresses', [AddressController::class, 'store'])->name('addresses.store');
            Route::put('/addresses/{address}', [AddressController::class, 'update'])->name('addresses.update');
            Route::post('/addresses/{address}/default', [AddressController::class, 'makeDefault'])->name('addresses.default');
            Route::delete('/addresses/{address}', [AddressController::class, 'destroy'])->name('addresses.destroy');

            // Cart and voucher slots (CartService, VoucherSessionService stateless copy).
            Route::get('/cart', [CartController::class, 'show'])->name('cart.show');
            Route::post('/cart/items', [CartController::class, 'store'])->name('cart.items.store');
            Route::delete('/cart/items', [CartController::class, 'destroyMany'])->name('cart.items.destroyMany');
            Route::patch('/cart/items/{item}', [CartController::class, 'update'])->whereNumber('item')->name('cart.items.update');
            Route::delete('/cart/items/{item}', [CartController::class, 'destroy'])->whereNumber('item')->name('cart.items.destroy');
            Route::get('/vouchers', [CartController::class, 'offers'])->name('vouchers.index');
            Route::post('/cart/vouchers', [CartController::class, 'applyVoucher'])->middleware('throttle:20,1')->name('cart.vouchers.apply');
            Route::delete('/cart/vouchers', [CartController::class, 'removeVoucher'])->name('cart.vouchers.remove');

            // Orders: list/detail, cancel, returns.
            Route::get('/orders', [OrderActionController::class, 'index'])->name('orders.index');
            Route::get('/orders/{order}', [OrderActionController::class, 'show'])->name('orders.show');

            // Wishlist.
            Route::get('/wishlist', [WishlistController::class, 'index'])->name('wishlist.index');
            Route::put('/wishlist/{product}', [WishlistController::class, 'store'])->whereNumber('product')->name('wishlist.store');
            Route::delete('/wishlist/{product}', [WishlistController::class, 'destroy'])->whereNumber('product')->name('wishlist.destroy');

            // Notifications (literal paths before the wildcard).
            Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
            Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.readAll');
            Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

            // Messages.
            Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
            Route::post('/messages', [MessageController::class, 'store'])->middleware('throttle:30,1')->name('messages.store');
            Route::post('/messages/open', [MessageController::class, 'open'])->name('messages.open');
            Route::get('/messages/{conversation}', [MessageController::class, 'show'])->name('messages.show');
            Route::get('/messages/{conversation}/images/{message}', [MessageController::class, 'image'])->name('messages.image');
        });

        // Checkout (COD) and order state changes: tighter limits.
        Route::middleware('throttle:20,1')->group(function (): void {
            Route::patch('/shipments/{shipment}/products/{product}/review', [\App\Http\Controllers\Api\Buyer\ReviewController::class, 'save'])->name('reviews.save');
            Route::post('/checkout/quote', [CheckoutController::class, 'quote'])->name('checkout.quote');
            Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:10,1')->name('checkout.store');
            Route::post('/orders/{order}/shipments/{shipment}/cancel', [OrderActionController::class, 'cancel'])->name('orders.cancel');
            Route::post('/orders/{order}/shipments/{shipment}/return', [OrderActionController::class, 'requestReturn'])->name('orders.return');
            Route::post('/orders/{order}/shipments/{shipment}/return/escalate', [OrderActionController::class, 'escalateReturn'])->name('orders.return.escalate');
        });
    });
});

/* --------------------------------------------------------------------------
 * Rider API
 * --------------------------------------------------------------------------
 * Same shape as the buyer group. A token issued to a buyer cannot reach
 * these routes because the "rider" ability is missing.
 * -------------------------------------------------------------------------- */

// Guests authenticate with these routes; NO token required.
Route::prefix('rider')->name('api.rider.')->group(function (): void {
    Route::post('/login', [RiderLoginController::class, 'login'])->middleware('throttle:api-login')->name('login');
});

// Public list for the registration form (same data as the web register page).
Route::prefix('rider')->name('api.rider.')->group(function (): void {
    Route::get('/providers', [RiderProviderController::class, 'index'])->middleware('throttle:60,1')->name('providers');
});

// Token-bearing routes.
Route::prefix('rider')->name('api.rider.')->group(function (): void {
    // Open to a rider in an Admin-granted wind-down as well: the same set the
    // web's EnsureRiderActive allows (account, deliveries out for delivery,
    // scan, proof/OTP, failed delivery, money, notifications, incidents). The
    // services still refuse anything but finishing a parcel already out.
    Route::middleware(['auth:sanctum', 'api.role:rider,wind-down'])->group(function (): void {
        Route::get('/profile', [RiderProfileController::class, 'index'])->name('profile');
        Route::post('/logout', [RiderLogoutController::class, 'store'])->name('logout');
        Route::post('/token/refresh', [RiderTokenController::class, 'refresh'])->middleware('throttle:10,1')->name('token.refresh');

        Route::middleware('throttle:60,1')->group(function (): void {
            Route::get('/jobs', [RiderJobController::class, 'index'])->name('jobs.index');
            Route::get('/earnings', [RiderJobController::class, 'earnings'])->name('earnings');
            Route::get('/notifications', [RiderNotificationController::class, 'index'])->name('notifications.index');
            Route::post('/notifications/read-all', [RiderNotificationController::class, 'markAllRead'])->name('notifications.readAll');
            Route::post('/notifications/{notification}/read', [RiderNotificationController::class, 'markRead'])->name('notifications.read');
        });
        Route::middleware('throttle:20,1')->group(function (): void {
            Route::post('/scan/verify', [RiderParcelActionController::class, 'verify'])->name('scan.verify');
            Route::post('/deliveries/{sellerOrder}/deliver', [RiderParcelActionController::class, 'deliver'])->name('deliveries.deliver');
            Route::post('/deliveries/{sellerOrder}/failed', [RiderParcelActionController::class, 'failDelivery'])->name('deliveries.failed');
            Route::post('/jobs/{sellerOrder}/incidents', [RiderIncidentController::class, 'store'])->name('jobs.incidents.store');
        });
        // Same limit as the web route: the buyer receives a new code each time.
        Route::post('/deliveries/{sellerOrder}/otp', [RiderParcelActionController::class, 'sendOtp'])
            ->middleware('throttle:5,1')->name('deliveries.otp');
    });

    // Fully operational riders only.
    Route::middleware(['auth:sanctum', 'api.role:rider'])->group(function (): void {
        Route::get('/history', [RiderJobController::class, 'history'])->middleware('throttle:60,1')->name('history');
        Route::middleware('throttle:20,1')->group(function (): void {
            Route::post('/availability', [RiderJobController::class, 'availability'])->name('availability');
            Route::post('/pickups/{sellerOrder}/confirm', [RiderParcelActionController::class, 'confirmPickup'])->name('pickups.confirm');
            Route::post('/deliveries/{sellerOrder}/start', [RiderParcelActionController::class, 'startDelivery'])->name('deliveries.start');
        });
    });
});

Route::prefix('rider')->name('api.rider.')->group(function (): void {
    Route::post('/register', [RegistrationController::class, 'store'])->middleware('throttle:register')->name('register');
    Route::post('/registration/resume', [RegistrationController::class, 'resume'])->middleware('throttle:register')->name('resume');
    Route::post('/registration/{rider}/verify-otp', [RegistrationController::class, 'verify'])
        ->middleware(['signed', 'throttle:otp-verify'])->name('verify');
    Route::post('/registration/{rider}/resend-otp', [RegistrationController::class, 'resend'])
        ->middleware(['signed', 'throttle:otp-resend'])->name('resend');
});

// Rider JSON reporting uses the existing session guard and CSRF protection.
Route::middleware(['web', 'auth:rider', 'rider.active'])->prefix('rider')->name('api.rider.')->group(function (): void {
    Route::post('/parcels/{sellerOrder}/incidents', [ParcelIncidentController::class, 'store'])
        ->name('parcels.incidents.store');
});
