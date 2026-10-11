# Mistakes Memory

Persistent record of non-trivial corrections so they are never repeated. Format: what happened / why wrong / correct rule / prevention.

## Hidden (not unrendered) markup leaks into assertDontSee tests

### What happened
The empty-cart page rendered the populated-cart shell (toolbar with "Select all", summary) and merely hid it with a Tailwind `hidden` class. `assertDontSee('Select all')` failed because the text was present in the HTML.

### Why it was wrong
Blade conditional content should not be rendered at all when the business state says it cannot apply; class-hiding is a JS concern, not a render concern, and it leaks text into the DOM/response.

### Correct rule
When a state (e.g. empty cart) makes a whole section meaningless, wrap it in `@if` so it is never rendered; the client JS then reloads the page to get the server-rendered empty state instead of un-hiding duplicated markup.

### Prevention
For `assertDontSee` assertions, always ensure the string is absent from the rendered HTML, not just visually hidden. Prefer server-rendered state over client-toggled markup for whole-page states.

## Redirecting a response loses the error message you intended to show

### What happened
Voucher validation errors flashed via `with('error', ...)` disappeared because a follow-up `redirect()` dropped the flashed data.

### Why it was wrong
Flash data survives exactly one redirect; chaining a second response (or re-rendering without the input) discards it.

### Correct rule
Either render the view directly with the error passed as a variable, or flash on the single redirect that is actually returned (`withErrors`/`with` + `back()->withInput()`), and read it consistently in the view (`session('error')` / `$errors`).

### Prevention
After writing any flash-then-redirect flow, trace the one response object actually returned and confirm the view reads the matching key. Feature-test the failure path, not just the happy path.

## A "stale test" may actually be a wrong assumption about the route under test

### What happened
`NavbarTest::test_signed_in_buyers_see_account_dropdown...` failed and looked like a regression from navbar edits. `route('home')` IS the landing page (`/`, `LandingPage.index`) — that part is correct. **The conclusion originally recorded here was wrong**: it claimed the landing shell's navbar include uses `variant => 'landing'`, so plain Login/Register for a signed-in buyer is "documented expected" behaviour. It is not.

### Why it was wrong
`Layouts/footer.blade.php` is the landing + register/pending/login shell and must pass `@include('Components.navbar', ['variant' => 'buyer'])` — commit `f85f485`, the same commit that added `NavbarTest`, sets it that way. With `'buyer'`, a signed-in buyer sees the account dropdown on `/` and guests still see plain Login/Register (`Components/navbar.blade.php` header confirms both variants render identically for guests). The include had only become `'landing'` in an uncommitted edit, so the test was reporting a REAL regression. Restoring `'buyer'` turns the suite green (138 passed).

### Correct rule
`Layouts/footer.blade.php` (public/landing + auth shell) → `variant => 'buyer'`. Never change it to `'landing'`: `'landing'` discards the signed-in buyer's account state on the landing page.

### Prevention
When a test fails, resolve the exact route/view under test (`php artisan route:list`) AND run `git diff` on the include/template before calling the test stale. A failure that the committed code does not produce points at YOUR uncommitted change, not at the test.

## Never git-stash to "check a pre-existing failure" without realizing untracked files go too

### What happened
`git stash push --include-untracked` to test the committed tree silently stashed this session's untracked feature tests and views, making the test file vanish mid-run.

### Why it was wrong
Untracked includes everything new; a test file "not found" error is a scary-sounding symptom of the stash, not of the code.

### Correct rule
Prefer `git stash push -- <paths>` limited to specific files, or `git worktree`/checkout of the commit in a temp dir, when probing a clean tree mid-task.

### Prevention
If an unexpected "file not found" appears immediately after a stash, `git stash pop` first, then diagnose. (Popped immediately here; no loss.)

## [Base-layer `span`/`button` color rule silently overrides inherited text utilities]

### What happened
Seller sidebar label colors were changed via utilities (`text-[#fff8f7]/90`, `text-[#e2b4bd]`) on the parent `<a>`/`<button>`, but the visible label `<span>` kept rendering `--text-secondary` (neutral-600 ≈ #525252) — near-invisible on the `#0a0a0a` sidebar. Multiple iterations of "new colors not appearing" all traced here, not to JIT purging or caching.

### Why it was wrong
`app.css` `@layer base` declares `body, p, span, label, input, select, textarea, button { color: var(--text-secondary) }`. That rule targets `span`/`button` DIRECTLY, and an element's own color declaration always beats an inherited one — layer order (utilities vs base) is irrelevant to inheritance. Only the lucide `<svg>` icons (not in that selector list) picked up the parent's color, which masked the bug.

### Correct rule
Any text-bearing `<span>` inside an element whose color is set by a utility class needs `text-inherit` (or its own color utility) to escape the base rule. Applies project-wide wherever `span`/`button`/`p` sit inside color-controlled wrappers.

### Prevention
When text color "won't change", check whether the text node is in a `<span>`/`<p>`/`<button>` targeted by the base-layer color rule before suspecting JIT purge or browser cache. Audit existing sidebars (admin, buyer nav) for the same pattern.

## Unrequested sidebar wired into a SHARED public shell (+ a phantom buyer sidebar)

### What happened
Uncommitted work changed `resources/views/Layouts/footer.blade.php` — it wrapped `@yield('content')` in a flex row with `@include('Components.sidebars.landing')` and switched the navbar include to `variant => 'landing'`. Three view components were left in `resources/views/Components/sidebars/` (`landing`, `buyer`, `auth`); none was included anywhere except that one line, two were malformed (two whole sidebars concatenated in one file; a `@php` block closed by a stray `</aside>`), and there was no contract for any of it. Developer response: *"dont change anything in the landing page also why is there a sidebar what im trying to say is to fix the navbar dont add sidebar for the buyer."*

### Why it was wrong
`Layouts/footer.blade.php` is **shared** — landing page, buyer register/pending pages, seller login, logistics register/OTP — so a "landing" sidebar silently appeared on all of them, and the `variant => 'landing'` switch came bundled with it (breaking `NavbarTest` on `/`). A sidebar in the buyer area was never requested and contradicts the buyer area's settled top-navbar-only layout (`Buyer/Layouts/app.blade.php`). Presenting an unapproved navigation change as a done feature is scope creep.

### Correct rule
Landing, buyer and auth navigation is the **top navbar only**. Never include a sidebar in `Layouts/footer.blade.php` or `Buyer/Layouts/app.blade.php`; sidebars belong only to the shells whose design owns one (`Layouts/seller`, `Layouts/logistics`, `Admin/Layouts/app`).

### Prevention
Before wiring a sibling area's pattern (sidebar rail) into a shell, list that shell's consumers (`Get-ChildItem -Recurse resources/views -Include *.blade.php | Select-String 'extends'`). Treat any edit to a SHARED layout as a change to every consumer, and never bundle an unrelated navbar variant change with a visual experiment. Unreferenced/duplicate component files must not be left in `resources/views/Components/`.

## Extracting JS into a shared Vite entry without loading it in every host (dead PSGC dropdowns)

### What happened
The buyer registration address cascades (province → municipality → barangay) were pulled out of
`resources/js/buyer/buyer-register-modal.js` into a new shared module `resources/js/buyer/address-lookup.js`
(exposed as `window.zfAddressLookup`) and the modal was rewritten to call it. Only
`Buyer/Account/address.blade.php` got the matching `@vite('resources/js/buyer/address-lookup.js')`; the two
views that actually host the modal — `Auth/Register-Type.blade.php` and `Buyer/Layouts/app.blade.php` — never
loaded it. On `/register` the province select therefore stayed on "Loading provinces…" forever, because the
modal resolves `window.zfAddressLookup` at init and silently skips the dropdowns when it is undefined
(`var addressLookup = window.zfAddressLookup ? … : null;`). Developer report: *"fix the API for the register's
address, it's not working."*

### Why it was wrong
A Vite entry only executes on pages whose `@vite(...)` call includes it. Extracting code into a module changes
that module's LOADING contract, so every host of the old inline behaviour had to be updated in the same
change. The failure was silent (no console error from the host path), and the external API was never at
fault: `https://psgc.gitlab.io/api/provinces/` answered 200 with `Access-Control-Allow-Origin: *` throughout.
The absence of an error message sent the search toward the API instead of the asset wiring.

### Correct rule
When JS moves into a shared entry, grep every host of the consuming component and add the entry to each,
listed BEFORE the script that consumes it. The buyer register modal always needs
`resources/js/buyer/address-lookup.js` before `buyer-register-modal.js` in both hosts
(`Auth/Register-Type.blade.php` line 77, `Buyer/Layouts/app.blade.php` line 228).

### Prevention
Before blaming an external API, fetch the rendered page and list its module tags
(`php artisan serve` + `Invoke-WebRequest` → regex on `<script type="module" src=…>`); if the consuming
module's dependency is absent, the bug is the Blade wiring, not the HTTP call. `Tests\Feature\RegisterAddressLookupTest`
now asserts the entry is present and ordered correctly on `/register` and on `/buyer`, and was confirmed to
fail against the pre-fix view.

## OTP mail branded "Laravel" by the scaffold `APP_NAME` while the app is Zefanya

### What happened
Developer report: *"THE OTP is not sending."* Everything server-side looked healthy — `storage/logs/laravel.log`
had `Buyer registration submitted, OTP sent` for the test buyer (`leanwolford@gmail.com`, 10:19:25) plus a
resend a minute later, `mail.default=smtp`, `jobs=0`/`failed_jobs=0`, and both `Mail::raw()` and the real
`BuyerVerifyOtp` notification sent successfully through Gmail SMTP from tinker. Rendering the notification was
decisive: `.env` still carried the scaffold `APP_NAME=Laravel`, so `MAIL_FROM_NAME="${APP_NAME}"` AND the
notification template's header/signature/footer all read **Laravel** while the subject read "Your Zefanya
verification code" — a brand-mismatched, 6-digit-code mail sent from a personal Gmail address, i.e. a textbook
phishing pattern that Gmail filters/spams and the recipient never sees.

### Why it was wrong
`APP_NAME` is not cosmetic: it is the sender name (`config('mail.from.name')`), the branding of every
notification mail, and the session/cache/redis prefixes. A `250 OK` from SMTP only means the relay accepted the
message — it says nothing about whether the recipient will ever see it, so "the log says it was sent" was not
evidence that the OTP was delivered.

### Correct rule
`APP_NAME=Zefanya` in both `.env` and `.env.example`. When mail "isn't sending": (1) confirm the transport with a
raw send, (2) confirm the app sent it via the log line *after* `notify()`, (3) **render the notification**
(`(new BuyerVerifyOtp('123456'))->toMail($buyer)->render()`) and read the visible text/From header the recipient
actually gets. Treat scaffold values (`Laravel`, `hello@example.com`) left in `.env` as defects.

### Prevention
Never diagnose mail delivery from the app log alone; the sender name, subject and body must agree on the brand.
Changing `APP_NAME` also renames the session cookie (`zefanya_session`) and cache/redis prefixes — existing dev
sessions log out once.



## A route pointing at a controller method that doesn't exist (and a missing Carbon import)

### What happened
While restyling the buyer My Account pages, a render test for `buyer.account.address` failed with
`Call to undefined method App\Http\Controllers\Buyer\AccountController::addressEdit()` — the same error the
developer's own `storage/logs/laravel.log` shows at 2026-09-26 10:16/10:18 (and 11:43/12:23 the day before).
`routes/buyer.php` declares GET/PATCH `buyer/account/address` → `addressEdit()` / `addressUpdate()`,
`Buyer/Account/address.blade.php` posts to that route and `AccountController::addressRules()` already existed for
it — but neither method had ever been written, so the whole Address tab 500'd. The same controller also called
`Carbon::parse()` in `update()` with **no `use Carbon\Carbon;`**, so saving the profile would 500 as well. Both
were in uncommitted work and neither was caused by the restyle.

### Why it was wrong
A route naming a missing method only fails when that page is visited, and a missing import only fails on the code
path using it — both are invisible to `php -l`, to `route:list`, and to any test that never hits the route. The
pages looked finished (view present, styles fine) while being unusable.

### Correct rule
When a page is added or changed, exercise every route it touches at least once (render **and** submit). A
controller method named by a route must exist before the view ships, and every class called in a namespace must be
imported (`Carbon\Carbon`, never a bare `Carbon::`).

### Prevention
`Tests\Feature\Buyer\BuyerAccountPagesTest` now renders all three account tabs and PATCHes the profile and address
forms, so a missing controller method or import fails the suite instead of only the browser.

