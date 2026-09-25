# Zefanya — Project Folder Structure

Generated 2026-09-18 (refreshed after the Tailwind v4 wrap-up of the buyer, seller, and admin CSS phases). One sentence per file; every purpose was inferred from the file's actual contents.
**Scope/exclusions:** binaries and assets are omitted (`public/Images/`, `public/favicon.ico`, `storage/app/` uploads, `database/database.sqlite`), as are generated outputs (`public/build/`, `bootstrap/cache/`, `.phpunit.result.cache`) and `.kilo/` (an editor worktree that duplicates the entire repo).

---

## Root files

- `AGENTS.md` — AI coding-agent instructions: Laravel Boost setup guidelines plus the project's own working rules and conventions.
- `CLAUDE.md` — same Laravel Boost guidelines as `AGENTS.md`, aimed at Claude-based agents.
- `artisan` — Laravel's CLI entry point used to run Artisan commands (migrations, serve, tinker, etc.).
- `composer.json` — defines the PHP dependency set (PHP `^8.3`, `laravel/framework ^13.17`, `laravel/tinker`) and the PSR-4 autoload map for `App\`, `Database\Factories\`, and `Database\Seeders\`.
- `composer.lock` — frozen exact-version resolution of `composer.json` for reproducible installs.
- `package.json` — Node build configuration: Vite 8, Tailwind CSS v4 + `@tailwindcss/vite`, `laravel-vite-plugin`, and the `lucide` icon package, with `dev`/`build` scripts.
- `package-lock.json` — frozen exact-version resolution of `package.json`.
- `phpunit.xml` — PHPUnit configuration wiring the `tests/Unit` and `tests/Feature` suites (plus an `app/` source directory entry).
- `README.md` — stock Laravel framework readme (not project-specific documentation).
- `test_buyer.php` — standalone curl script that drives the buyer registration flow end-to-end against `http://127.0.0.1:8000` outside PHPUnit.
- `vite.config.js` — Vite configuration registering the Tailwind v4 plugin and six entry points: the canonical `app.css`, the three remaining auth-chain stylesheets (`login-modal.css`, `auth.css`, `buyer/buyer-register-modal.css`), and the two buyer JS bundles (`buyer.js`, `home.js`); every per-area legacy CSS entry was pruned during the migration.
- `.env` — the actual local environment values (app key, database, mail) read at runtime and kept out of version control.
- `.env.example` — environment template showing the expected variables (SQLite connection, database sessions, mail settings).
- `.editorconfig` — shared editor formatting rules (indentation, charset, line endings) for consistent files across editors.
- `.gitattributes` — Git line-ending and diff handling rules per file type.
- `.gitignore` — lists files and directories Git must ignore (dependencies, build output, local secrets).
- `.npmrc` — npm runtime configuration for the JS toolchain.

## app/

### app/Exceptions/

- `ForbiddenBuyPageException.php` — custom 403 HTTP exception thrown when a buyer reaches a seller-only route, so the global handler renders a friendly "sellers only" message instead of Laravel's raw 403 page.

### app/Http/Controllers/

- `Controller.php` — abstract base controller every other controller extends; intentionally empty.

### app/Http/Controllers/Admin/

- `AuthController.php` — handles admin login (showing the login page when unauthenticated, redirecting to the dashboard when already signed in) and logout against the `admin` guard.
- `DashboardController.php` — renders the admin dashboard view with the authenticated admin's name.
- `RegistrationController.php` — powers the unified registration queue: lists pending buyers and sellers and approves/rejects either type, sending the matching decision notification.
- `SellerRegistrationController.php` — renders `Admin.Registrations.sellers` for a sellers-only review list with approve/reject actions; **appears orphaned** — no route in `routes/admin.php` references this controller, and the view calls `registrations.sellers.*` routes that are not defined.

### app/Http/Controllers/Auth/

- `LoginChoiceController.php` — the single `GET /login` entry point that serves both buyer and seller login from one screen (the app deliberately has exactly one login surface).
- `UnifiedLoginController.php` — the `POST` counterpart that validates credentials and logs the user into the correct guard (buyer, seller, or admin), rethrowing validation errors to the shared form.

### app/Http/Controllers/Buyer/

- `AccountController.php` — empty placeholder scaffolded for future buyer account features (no methods yet).
- `CartController.php` — implements `POST /buyer/cart/add`, returning JSON with a 401 for guests so the frontend can open the login modal instead of redirecting.
- `CategoryController.php` — empty placeholder scaffolded for future buyer category browsing (no methods yet).
- `ChatController.php` — empty placeholder scaffolded for future buyer–seller chat (no methods yet).
- `CheckoutController.php` — empty placeholder scaffolded for future buyer checkout (no methods yet).
- `HomeController.php` — renders the buyer home page, passing the logged-in buyer's display name and a hard-coded cart count (`TODO` until a cart model exists).
- `LoginController.php` — legacy standalone buyer login GET/POST kept in case login is a page rather than the modal; its docblock admits the modal may have made it unused.
- `OrderController.php` — empty placeholder scaffolded for future buyer order history (no methods yet).
- `ProductController.php` — empty placeholder scaffolded for future buyer product listing/detail (no methods yet).
- `RegisterBuyerController.php` — stores a validated buyer registration (hashing the password), generates and emails the verification OTP, and hands off to the OTP flow.
- `VerifyBuyerOtpController.php` — shows the OTP entry step, verifies the 6-digit emailed code (marking the email verified), and resends fresh codes on demand.

### app/Http/Controllers/Seller/

- `AuthController.php` — forwards any traffic still hitting `/seller/login` to the unified login entry point and handles seller logout.
- `DashboardController.php` — computes the seller dashboard's stats (total sales, orders, pending orders, product counts, 7-day sales trend) for the dashboard view.
- `ProductController.php` — full CRUD for the seller's products, including image uploads via `Storage` and ownership checks against the authenticated seller.
- `RegisterSellerController.php` — stores a validated seller registration (business info, IDs, permits), hashes the password, and emails the registration OTP.
- `VerifySellerOtpController.php` — shows/verifies/resends the seller's 6-digit registration OTP, guarding against non-pending sellers.

### app/Http/Middleware/

- `Admin/EnsureAdminRole.php` — route middleware (`admin.role:<role>`) that verifies the authenticated admin holds the required admin role.
- `Seller/EnsureSellerApproved.php` — route middleware (`seller.approved`) that redirects unauthenticated visitors to login and blocks sellers whose account is not approved.

### app/Http/Requests/

- `Buyer/StoreBuyerRegistrationRequest.php` — validation rules for buyer registration, switching between manual-address and barangay-select address modes.
- `Seller/StoreProductRequest.php` — validation rules for creating/updating a seller product, relaxing required fields on updates.
- `Seller/StoreSellerRegistrationRequest.php` — validation rules for seller registration (personal info, business details, uploads, address mode).

### app/Models/

- `Product.php` — the primary product model (`App\Models\Product`) with soft deletes, seller/category/image relations, and slug handling.
- `ProductImage.php` — product image model exposing `product_id`, `path`, `is_primary`, and `sort_order` as fillable.
- `Admin/Admin.php` — Eloquent `Authenticatable` for the `admins` table used by the `admin` guard.
- `Buyer/Buyer.php` — Eloquent `Authenticatable` for buyers with notification support and name/status helpers.
- `Seller/Product.php` — **appears to be an accidental duplicate**: despite living in `app/Models/Seller/`, it declares `App\Models\Product` with the same class body as `app/Models/Product.php`, making it dead code that conflicts by class name.
- `Seller/ProductImage.php` — **empty file (0 lines)** — purpose unclear; appears to be an abandoned placeholder.
- `Seller/Seller.php` — Eloquent `Authenticatable` for sellers holding personal + business fields and their category relation.
- `Shared/Category.php` — simple category model with `icon` (lucide name) and `label` fillable fields, shared across areas.
- `Shared/User.php` — Laravel's default `users`-table model, rewritten to use PHP attribute-based `#[Fillable]`/`#[Hidden]` declarations.

### app/Notifications/

- `Buyer/BuyerRegistrationDecision.php` — mail notification telling a buyer their account was approved or rejected (deliberately not queued due to low volume).
- `Buyer/BuyerVerifyOtp.php` — mail notification delivering the raw 6-digit buyer verification code.
- `Seller/SellerRegistrationDecision.php` — mail notification delivering the admin's approve/reject decision (and reason) to a seller.
- `Seller/SellerRegistrationOtp.php` — mail notification delivering the seller's registration OTP code.

### app/Providers/

- `AppServiceProvider.php` — boots the app: loads `routes/buyer.php` under the `web` middleware (admin/seller routes load via `bootstrap/app.php`'s `then:` closure) and registers the capital-C `resources/views/Components` folder as an anonymous component path so `x-seller.*` components resolve on case-sensitive filesystems.

## bootstrap/

- `app.php` — application bootstrap: wires routing (`web.php` plus `admin.php`/`seller.php` groups via a `then:` closure), middleware aliases (`admin.role`, `seller.approved`), guest-redirect behavior, and exception handling.
- `providers.php` — returns the list of service providers Laravel should register.

## config/

- `app.php` — core Laravel app configuration (name, environment, locale, encryption, providers, aliases).
- `auth.php` — customized auth config defining **three separate guards and providers** (`admin`, `buyer`, `seller`), each pointed at its own model.
- `cache.php` — cache store configuration (default database/file stores).
- `database.php` — database connection configuration (SQLite default, other drivers available).
- `filesystems.php` — filesystem disks, including the `local` private disk rooted at `storage/app/private` that holds registration IDs and business permits.
- `logging.php` — log channel configuration (single stack channel by default).
- `mail.php` — mailer/transport configuration used by the registration OTP and decision notifications.
- `queue.php` — queue connection configuration (the registration notifications deliberately do not use it).
- `services.php` — third-party service credentials (empty placeholders by default).
- `session.php` — session configuration using the database driver.

## contracts/

- `2026-09-07-buyer-guest-access.md` — approved change contract for the buyer guest-access routing split (guest catalog vs `auth:buyer`-guarded pages); one markdown contract per high-risk change is stored in this folder.

## database/

- `.gitignore` — keeps SQLite databases and other local database artifacts out of Git.

### database/factories/

- `UserFactory.php` — model factory for the default `User` model (hashed test password, unique emails).

### database/migrations/

- `0001_01_01_000000_create_users_table.php` — Laravel's default migration creating `users`, `password_reset_tokens`, and `sessions`.
- `0001_01_01_000001_create_cache_table.php` — creates the `cache` and `cache_locks` tables for the database cache driver.
- `0001_01_01_000002_create_jobs_table.php` — creates the `jobs`, `job_batches`, and `failed_jobs` tables for the queue driver.
- `2024_01_01_000000_create_admins_table.php` — creates the `admins` table backing the admin guard.
- `2025_09_05_000000_create_buyers_table.php` — creates the `buyers` table; its docblock warns that a stray pre-existing `buyers` table must be dropped first.
- `2025_09_05_100000_create_categories_table.php` — creates the `categories` table (`icon` = lucide icon name, `label` = display name).
- `2025_09_05_100001_create_sellers_table.php` — creates the `sellers` table with personal, business, address, status, and upload-path columns.
- `2026_09_12_000001_create_products_table.php` — creates the `products` table with seller/category foreign keys, pricing, stock, and status columns.
- `2026_09_12_000002_create_product_images_table.php` — creates the `product_images` table with path, primary flag, and sort order per product.

### database/seeders/

- `DatabaseSeeder.php` — default seeder that creates a single factory user; no domain data is seeded yet.

## docs/

- `css-migration-notes.md` — running ledger for the legacy-CSS → Tailwind v4 migration: per-area chunk status, hazards, decisions, and deletion gates.
- `folder-structure.md` — this file: a one-sentence-per-file map of the project tree.
- `seller_coding_checklist.md` — progress checklist of seller-module features (registration, OTP, approval flow, …) with completed items ticked.
- `ui-design-spec.md` — the project's declared "UI source of truth" design guide.

## public/

- `.htaccess` — Apache rewrite rules that route all requests through `index.php`.
- `index.php` — the HTTP front controller that boots the Laravel kernel for every request.
- `robots.txt` — crawler directives for search engines.
- `js/lucide.min.js` — vendored minified Lucide icon library loaded by page layouts to render `data-lucide` icons client-side.

## resources/css/

- `app.css` — the Tailwind v4 entry point: `@theme` design tokens, `@layer base` element rules plus the legacy-token `:root` re-export, and a small `@layer components` block for JS-driven seller-sidebar collapse state; the single canonical token source since `design-system.css` was deleted.
- `auth.css` — two-line aggregator that `@import`s the remaining legacy auth stylesheets (`auth-pages.css` and `buyer/buyer-register-modal.css`); pending conversion.
- `auth-pages.css` — legacy (unconverted) styles for the shared registration/login pages that extend `Layouts.footer`.
- `login-modal.css` — legacy (unconverted) styles for the global login modal component.
- `buyer/buyer-register-modal.css` — legacy (unconverted) styles shared by the buyer and seller registration + OTP-verify modals.

## resources/js/

- `app.js` — essentially an empty stub (a single comment line) and not referenced by `vite.config.js` — appears unused.
- `buyer/buyer.js` — shared buyer-page runtime: account dropdown, cart drawer, quick-view modal, chat panel, and toast toggles for the widgets living in the buyer layout, plus the add-to-cart fetch with login-modal fallback for guests; wires the navbar/widget buttons with event listeners (replacing inline onclick handlers, which don't work under Vite ES modules) and stores a pending cart action so guests can retry after logging in.
- `buyer/home.js` — loaded only on the buyer home page; builds the flash-sale and recommended product cards as Tailwind HTML strings (⚠️ must stay in lockstep with the Blade card markup) and depends on `buyer.js` for `addToCart`/`openQuickView`.

## resources/views/

### resources/views/Admin/

- `dashboard.blade.php` — admin dashboard page showing a welcome header and a four-item summary strip (active users, pending registrations, disputes, commission).
- `Auth/login.blade.php` — standalone full-HTML admin login page with the Zefanya card, email/password inputs, and remember-me (the only admin entry point outside the shared layout).
- `Layouts/app.blade.php` — admin shell layout: sidebar include, topbar with page title and signed-in admin name, and the `admin-content` main region.
- `Registrations/index.blade.php` — unified registration review page: pending buyer/seller list on the left, detail panel with documents and approve/reject (plus a revealable rejection-reason form) on the right.
- `Registrations/sellers.blade.php` — sellers-only variant of the review page; **appears orphaned** — it is only rendered by the unused `SellerRegistrationController` and calls `registrations.sellers.*` routes that don't exist.

### resources/views/Auth/

- `Register-Type.blade.php` — "how do you want to register?" chooser page whose Buyer/Seller cards open the corresponding registration modals.
- `register-buyer-pending.blade.php` — buyer "pending admin approval" page; **appears unused** — no route or controller references it (`Buyer/register-buyer-pending` is the one rendered).

### resources/views/Buyer/

- `Home/index.blade.php` — the real, converted buyer home page: hero, explore-categories carousel, flash-sale/recommended product sections.
- `Layouts/app.blade.php` — buyer shell layout: includes `Components.navbar` with `variant='buyer'` (dark filled Login / outlined Register), loads `app.css` plus the remaining auth-chain CSS (`login-modal.css`, `buyer/buyer-register-modal.css`) and adds the `.html-body` shim, then yields content and mounts the global widgets (cart drawer, quick-view modal, chat panel, toast) that `buyer.js` drives.
- `Layouts/footer.blade.php` — buyer footer (footer grid, link columns, legal line) converted to Tailwind utilities.
- `Auth/login.blade.php` — standalone buyer login page rendered by `Buyer\LoginController` for the `buyer.login` route (kept alongside the login-modal flow).
- `Account/index.blade.php` — stub placeholder for the account overview menu (links to profile/address/password).
- `Account/profile.blade.php` — stub placeholder for the profile form (name, sex, birthday, contact).
- `Account/address.blade.php` — stub placeholder for the saved-addresses screen.
- `Account/password.blade.php` — stub placeholder for the change-password form.
- `Cart/index.blade.php` — stub placeholder for the cart (view cart, vouchers, payment, place order).
- `Categories/index.blade.php` — stub placeholder for the all-categories grid.
- `Categories/show.blade.php` — stub placeholder for one category's product listing (meant to reuse the product-card partial).
- `Chat/index.blade.php` — stub placeholder for the conversation list (one row per seller).
- `Chat/show.blade.php` — stub placeholder for a single chat thread.
- `Checkout/index.blade.php` — stub placeholder for finalizing order details before payment.
- `Checkout/success.blade.php` — stub placeholder for the order-success confirmation screen.
- `Orders/index.blade.php` — stub placeholder for the buyer order list.
- `Orders/show.blade.php` — stub placeholder for a single order's detail/tracking view.
- `Products/index.blade.php` — stub placeholder for the product listing with search/filter results.
- `Products/show.blade.php` — stub placeholder for the product detail page (variations, quantity, add to cart).
- `register-buyer-check-email.blade.php` — legacy deep-link page telling the buyer to check their email for the code; kept for direct links while the modal flow uses the OTP modal.
- `register-buyer-pending.blade.php` — page shown after email verification while the buyer awaits admin approval.

### resources/views/Components/

- `admin-sidebar.blade.php` — dark admin sidebar: brand block, nav links with active-state utilities, pending-registration badge, and logout form (converted to Tailwind in ADMIN-b).
- `navbar.blade.php` — shared site navbar (brand, search bar, cart/wishlist icons, Login/Register links) with a `variant` prop ('landing' default | 'buyer' — dark filled Login + outlined Register, fully Tailwind since `auth-buttons.css` was deleted); also handles `showRegisterModal`, `hideGuestInLogin`, the cart count, the login-modal trigger, and links Login to `/login` on auth pages.
- `product-card.blade.php` — reusable product card partial (image, title, price, optional old price and SALE/NEW badge) used by landing and buyer listings.
- `category-chip.blade.php` — small category chip partial (lucide icon + label) used in category rows.
- `login-modal.blade.php` — global login modal included once per layout and opened from any `[data-login-trigger]` element.
- `buyer-register-modal.blade.php` — buyer registration modal as a single four-step flow (Personal → Address → ID Verification → OTP), which absorbed the old separate OTP modal (that component was deleted); opened from the register-type page's Buyer card or directly from the buyer navbar's Register button.
- `seller-register-modal.blade.php` — seller registration modal (personal + business + uploads) opened from the register-type page's Seller card.
- `seller-verify-otp-modal.blade.php` — seller 6-digit OTP modal shown after successful seller registration.
- `seller/sidebar.blade.php` — seller sidebar root (`x-seller.sidebar`): brand, sectioned nav, user panel, logout, plus the vanilla-JS collapse toggle persisted in localStorage.
- `seller/sidebar-brand.blade.php` — sidebar brand block (logo mark, "Zefanya" wordmark, seller-panel label).
- `seller/sidebar-nav.blade.php` — renders the sidebar's sectioned navigation by looping sections and delegating each item to the nav-item component.
- `seller/sidebar-nav-item.blade.php` — one sidebar link with route resolution, active-state detection, and Tailwind active/hover utilities.
- `seller/sidebar-user-panel.blade.php` — sidebar footer block showing the seller's avatar initial, name, and store label.
- `seller/sidebar-logout.blade.php` — sidebar logout form submitting the unified logout route so shared sessions clear every guard.

### resources/views/LandingPage/

- `index.blade.php` — the public landing page: hero, explore-categories carousel, and recommended-products section, composed from the shared components.

### resources/views/Layouts/

- `footer.blade.php` — base HTML document shell (fonts, `app.css`, navbar include, footer, `@yield('content')`) extended by landing and all auth pages.
- `seller.blade.php` — seller-area shell layout (fonts, `app.css`, sidebar component, padded main region, lucide init).

### resources/views/Seller/

- `Dashboard/index.blade.php` — real seller dashboard: welcome header, four stat cards, sales-performance chart, and side widgets.
- `Auth/login.blade.php` — legacy seller login page (extends `Layouts.footer`); likely never rendered since `Seller\AuthController` forwards `/seller/login` to the unified login.
- `register-seller-pending.blade.php` — page shown after seller email verification while awaiting admin approval.
- `register-seller-verify-otp.blade.php` — non-modal seller OTP verification page kept as a deep-link alternative to the OTP modal.
- `Account/index.blade.php` — stub seller account page (heading plus placeholder content).
- `Account/edit.blade.php` — stub seller account-edit page (heading plus placeholder content).
- `Chat/index.blade.php` — stub seller messages page (heading plus placeholder content).
- `Feedback/index.blade.php` — stub customer-feedback page (heading plus placeholder content).
- `Inventory/index.blade.php` — stub inventory-management page (heading plus placeholder content).
- `Orders/index.blade.php` — stub seller order-list page (heading plus placeholder content).
- `Orders/show.blade.php` — stub order-details page (heading plus placeholder content).
- `Products/index.blade.php` — real seller product listing with success flash, product cards, and create/edit/delete entry points.
- `Products/create.blade.php` — "Add Product" page header that includes the shared product form.
- `Products/edit.blade.php` — "Edit Product" page header (with the product's name) that includes the shared form.
- `Products/show.blade.php` — **byte-identical duplicate of `_form.blade.php`** (88 lines each, zero diff) — appears to be a copy-paste mistake rather than a detail view.
- `Products/_form.blade.php` — shared product create/edit form partial (CSRF/method spoofing, validation errors, fields, image uploads).
- `Reports/index.blade.php` — stub sales-reports page (heading plus placeholder content).
- `Shipments/index.blade.php` — stub shipment-list page (heading plus placeholder content).
- `Shipments/show.blade.php` — stub shipment-details page (heading plus placeholder content).
- `Vouchers/index.blade.php` — stub voucher-list page (heading plus placeholder content).
- `Vouchers/create.blade.php` — stub create-voucher page (heading plus placeholder content).
- `Vouchers/edit.blade.php` — stub edit-voucher page (heading plus placeholder content).

## routes/

- `admin.php` — admin routes (`/admin` prefix): guest login, authenticated dashboard/logout, and the unified registration approve/reject endpoints.
- `buyer.php` — buyer routes (`/buyer` prefix): guest-accessible catalog pages plus auth-gated cart/checkout/orders/chat/account entry points.
- `console.php` — Artisan closure commands (only the stock `inspire` command).
- `seller.php` — seller routes (`/seller` prefix): login forwarding, and the `seller.approved`-gated dashboard, products CRUD, and placeholder pages.
- `web.php` — top-level routes: landing page, unified `GET /login`, register-type page, and the buyer/seller registration + OTP verify/resend/pending flows (throttled).

## storage/

- `new-hero-css.txt` — leftover scratch file holding a 95-line plain-CSS draft of the landing "explore panel"; not referenced by any view or build input.
- `app/.gitignore`, `app/public/.gitignore` — Git placeholders keeping the storage directory tree on disk while ignoring its contents.

## tests/

- `TestCase.php` — base test class bootstrapping the Laravel application for all tests.
- `Feature/ExampleTest.php` — stock feature test asserting the root `/` endpoint returns a successful response.
- `Unit/ExampleTest.php` — stock unit test asserting a trivially true condition (Laravel scaffold placeholder).
