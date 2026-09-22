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
`NavbarTest::test_signed_in_buyers_see_account_dropdown...` failed and looked like a regression from navbar edits. Investigation showed `route('home')` is the landing page (`/`, `LandingPage.index`), whose navbar include uses `variant => 'landing'` — documented to always show plain Login/Register for guests and buyers alike.

### Why it was wrong
Assuming `home` = buyer home (`buyer.home`) led to hunting a regression that did not exist in the tested path.

### Correct rule
Resolve the actual route/view a failing test exercises before blaming recent changes; `php artisan route:list` first, diff of the touched files second.

### Prevention
When a test fails, identify the exact controller/view under test and read its variant/branch conditions before editing code that merely looks related.

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

