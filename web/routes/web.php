    <?php

    // Add to routes/web.php

    use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\UnifiedLoginController;
use App\Http\Controllers\Buyer\RegisterBuyerController;
use App\Http\Controllers\Buyer\VerifyBuyerOtpController;
use App\Http\Controllers\Logistics\RegisterLogisticsController;
use App\Http\Controllers\Logistics\VerifyLogisticsOtpController;
use App\Http\Controllers\Seller\RegisterSellerController;
use App\Http\Controllers\Seller\VerifySellerOtpController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    // Landing is PUBLIC ONLY: a signed-in buyer belongs on their own home
    // (Buyer/Layouts/app.blade.php → navbar variant 'buyer'). Plain redirect —
    // the session is deliberately never touched here (no logout, invalidate,
    // flush or regenerate).
    if (Auth::guard('buyer')->check()) {
        return redirect()->route('buyer.home');
    }

    return view('LandingPage.index');
})->name('home');

// Unified login entry point (GET) — redirects to register page
Route::get('/login', function () {
    return redirect()->route('register.type');
})->name('login');

Route::get('/register', function () {
    return view('Auth.Register-Type');
})->name('register.type');

Route::get('/register/buyer', function () {
    return redirect()->route('register.type', ['open' => 'buyer']);
})->name('register.buyer');

Route::post('/register/buyer', [RegisterBuyerController::class, 'store'])
    ->name('register.buyer.store')
    ->middleware('throttle:5,1');

// Step 1 of 2: shown immediately after submitting, before email is confirmed.
// Legacy page kept for direct deep-links; modal flow uses the OTP modal instead.
Route::get('/register/buyer/check-email', function () {
    return view('Buyer.register-buyer-check-email');
})->name('register.buyer.check-email');

// POST: validate the 6-digit code (JSON for the modal).
Route::post('/register/buyer/verify-otp/{buyer}', [VerifyBuyerOtpController::class, 'verify'])
    ->name('register.buyer.verify-otp');

// POST: generate a fresh code + resend email (throttled).
Route::post('/register/buyer/verify-otp/{buyer}/resend', [VerifyBuyerOtpController::class, 'resend'])
    ->name('register.buyer.verify-otp.resend')
    ->middleware('throttle:3,1');

// Step 2 of 2: shown after email is confirmed, while waiting on admin approval.
Route::get('/register/buyer/pending', function () {
    return view('Buyer.register-buyer-pending');
})->name('register.buyer.pending');

// Seller registration routes
Route::get('/register/seller', function () {
    return redirect()->route('register.type', ['open' => 'seller']);
})->name('register.seller');

Route::post('/register/seller', [RegisterSellerController::class, 'store'])
    ->name('register.seller.store')
    ->middleware('throttle:5,1');

Route::post('/register/seller/verify-otp', [VerifySellerOtpController::class, 'verify'])
    ->name('register.seller.verify-otp')
    ->middleware('throttle:10,1');

Route::post('/register/seller/verify-otp/resend', [VerifySellerOtpController::class, 'resend'])
    ->name('register.seller.verify-otp.resend')
    ->middleware('throttle:3,1');

Route::get('/register/seller/pending', function () {
    return view('Seller.register-seller-pending');
})->name('register.seller.pending');

// Logistics registration routes — same shape as the seller flow. The GET entry
// point forwards to the register-type page and opens the logistics modal
// (previously it rendered Auth.register-logistics, which never existed → 500).
Route::get('/register/logistics', function () {
    return redirect()->route('register.type', ['open' => 'logistics']);
})->name('register.logistics');

Route::post('/register/logistics', [RegisterLogisticsController::class, 'store'])
    ->name('register.logistics.store')
    ->middleware('throttle:5,1');

Route::get('/register/logistics/verify-otp/{logistics_provider}', [VerifyLogisticsOtpController::class, 'show'])
    ->name('register.logistics.verify-otp');

Route::post('/register/logistics/verify-otp/{logistics_provider}', [VerifyLogisticsOtpController::class, 'verify'])
    ->name('register.logistics.verify-otp.store')
    ->middleware('throttle:10,1');

Route::post('/register/logistics/verify-otp/{logistics_provider}/resend', [VerifyLogisticsOtpController::class, 'resend'])
    ->name('register.logistics.verify-otp.resend')
    ->middleware('throttle:3,5');

Route::get('/register/logistics/pending', function () {
    return view('Logistics.register-logistics-pending');
})->name('register.logistics.pending');

Route::get('/shop', function () {
    return view('LandingPage.index');
})->name('shop.browse');

// ============================================================================
// Unified login entry point
// ============================================================================
// POST /login  →  Auth\UnifiedLoginController@login
//   Tries seller guard first, then buyer. Redirects by role:
//     seller → seller.dashboard
//     buyer  → buyer.home
// Standalone auth pages (buyer/login, seller/login) point here too so the
// modal and the no-JS pages share a single auth backend.
Route::post('/login', [UnifiedLoginController::class, 'login'])
    ->name('unified.login')
    ->middleware('throttle:5,1');

// ============================================================================
// Google Sign-In  →  Auth\GoogleAuthController
// ============================================================================
// /auth/google/redirect + /callback are ROLE-AGNOSTIC: the callback resolves
// buyer | seller | logistics from the Google email. They power the single
// "Continue with Google" button in the shared login modal.
// /auth/google/{role}/redirect + /callback are the role-scoped pair for any
// surface that already knows which panel is being signed in to.
// LINK flows only — no account is ever created here, and the same
// "approved" gate as password sign-in is applied before a session opens.
Route::prefix('auth/google')->name('auth.google.')->middleware('throttle:10,1')->group(function (): void {
    Route::get('/redirect', [GoogleAuthController::class, 'redirect'])->name('redirect');
    Route::get('/callback', [GoogleAuthController::class, 'callback'])->name('callback');

    Route::get('/{role}/redirect', [GoogleAuthController::class, 'redirectForRole'])
        ->where('role', implode('|', GoogleAuthController::ROLES))
        ->name('role.redirect');

    Route::get('/{role}/callback', [GoogleAuthController::class, 'callbackForRole'])
        ->where('role', implode('|', GoogleAuthController::ROLES))
        ->name('role.callback');
});

// POST /logout  → clears every active guard (seller + buyer) and returns home.
Route::post('/logout', [UnifiedLoginController::class, 'logout'])
    ->name('unified.logout')
    ->middleware('throttle:5,1');
