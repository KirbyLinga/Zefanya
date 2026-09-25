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


