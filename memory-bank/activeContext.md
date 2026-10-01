# Active Context

## Where things stand

**The app can talk to its own server now.** A real API surface exists on the
Laravel side (`web/routes/api.php` + Sanctum), and the app's live auth repository
calls it for buyers: register (multipart, ID document and all) → confirm the
emailed code → sign in → `GET /me` → sign out. The mock/live decision is still one
config line in `main()`:

```dart
final repositories = Repositories.forMode(useLive: kUseLiveApi, baseUrl: kApiBaseUrl);
```

`flutter analyze` is clean; **204 mobile tests pass** (184 two passes ago) and
**29 server tests pass** in `tests/Feature/Api`. Every live call has been driven
at least once over real HTTP against real MySQL — probe rows since deleted.

What this session actually changed:

1. **A pre-existing bug was found and fixed, and it was a blocker.**
   `registration_screen.dart` wrapped its `PageView` in an `AnimatedSwitcher`
   keyed on `_step`. Changing that key rebuilt the PageView while the outgoing
   copy was still fading, so for 200ms two PageViews existed and both built the
   same `Form(key: _formKeys[id])` widgets — one set of `GlobalKey<FormState>`s,
   two subtrees claiming them. The framework resolved that by reparenting the
   elements back into the outgoing subtree, which left the incoming page without
   its children: **the step band advanced while the form content stayed on step
   one, permanently, from the first Continue onwards.** Every step after the
   first has been unreachable in the app. Proved *pre-existing* by stashing this
   session's `lib/` changes and reproducing it on the committed code. Fixed by
   removing the wrapper — the PageView's own page animation is the motion the
   switcher was standing in for.
2. **The password step.** A new `_StepId.credentials` between Personal and
   Address, with the server's `min:8` + confirmation mirrored in its validators.
   Every `_StepId` switch is exhaustive, so adding a step was compile-checked at
   each site — and the step *numbers* are now derived from position
   (`_steps.indexOf(id) + 1`) instead of typed into each caption, which is
   exactly what inserting a step would otherwise have silently broken.
3. **`AuthState.submitForApproval()` is async and goes through the
   repository.** It used to set `pendingApproval` itself, with a comment
   explaining that `register()` needs a password the form did not collect and
   that passing `''` would be inventing a credential. Now the form collects one.
   The *gate* is persisted (`auth_registration_status`, a new additive key) and
   survives a cold start.
4. **The pending screen tells the two gates apart.** `awaitsEmailVerification`
   — which existed with no callers — now decides between "we emailed a code to
   <address>" and the reviewer copy. The gate copy is deliberately *not* gated on
   `kShowDevTools`: a repository answering `pending_verification` is one that
   reached a server which really sent a code. It names the action and does **not**
   offer an in-app code field, because this build has no OTP screen.
5. **`lib/data/dto/registration_payload.dart`** — the one file that knows what
   the server calls the form's answers. `register()` takes a typed
   `RegistrationProfile` (app vocabulary) instead of `Map<String, Object?>`,
   which would have made the wire keys a hidden contract spelled by a screen.
6. **Two latent overflows were found by the new tests** and fixed with the
   canonical remedies: `Flexible` around the pending screen's status text and
   the DOB picker's date, and `isExpanded: true` on every dropdown in the
   registration form (a `DropdownButton`'s row is as wide as its *hint*, so a
   long hint or a large text scale pushes the arrow off the field). The Address
   step had never been rendered before, because of (1).

7. **The OTP screen exists, so the email gate now has an action in it.** Until
   this, the pending screen told someone "we emailed you a code" and then offered
   nothing to do with it: the app describing a step and declining to provide it.
   `lib/screens/auth/otp_screen.dart` takes the six digits (digits-only and
   six-long, enforced by input formatters, so the validator answers only for
   *completeness*), `AuthState.confirmEmail()`/`resendConfirmationCode()` submit
   them through `AuthRepository.verifyOtp`/`resendOtp`, and a successful answer
   unwinds to `AuthGate` — this app's single route root — so the person lands on
   the *other* gate rather than on a screen describing it.
8. **The handle is carried, persisted, and never invented.** `AuthResult` gained
   an opaque `verificationId` (the server's own row id: an email is not an id),
   `AuthState` persists it (`auth_verification_id`, additive), and
   `canConfirmEmail` requires **both** the gate *and* the handle. A
   `pending_verification` with no handle renders no field at all, because every
   call the app could make would need something it does not have.
9. **The mock cannot honestly open the email gate, so a dev-tools affordance
   does.** Sending mail is not something the mock can do, and answering
   `pending_verification` would send someone to an inbox that will never receive
   anything — the exact lie the pending screen was rewritten to stop telling.
   `AuthState.debugStartEmailVerification()` mirrors `debugApprovePendingAccount`
   and opens the gate with `kMockVerificationId`, while `MockAuthRepository`
   accepts `kMockOtpCode` and refuses every other code: the screen is reachable
   by hand *and* its failure path is reachable.
10. **A refused code is a rejection, not a validation error.** The server's 422
    for a wrong code carries `{ok: false, message}` and no `errors` bag, so the
    mock throws `RequestRejectedException` and the screen shows the server's own
    sentence inline above the field (cleared on edit). The resend link counts
    down 30s, but only after *this* screen sends a code — someone who opened the
    screen because no mail came must be able to ask immediately.

## What the earlier session did (still the base of the data layer)

1. **`ChatState`, `ReviewState`, `CourierState` and `CartState` are no longer
   stores.** Each took over-passing its data to a repository: the mock owns the
   storage (keys `chat_threads`, `product_reviews`, `placed_orders` — unchanged,
   byte for byte), and the state class is a cache the synchronous getters read.
   `CartState` also gets the voucher catalogue from `CartRepository` instead of
   the `mockVouchers` constant, so the two screens that list vouchers can no
   longer disagree with the data layer.
2. **`AuthState` went through `AuthRepository`** for login/logout. This was the
   last place a *live* build would have faked reality: `login()` used to set
   `status = active` itself, so a live build would have claimed a session the
   server never issued. The status is now the repository's answer.
3. **`main()` hands every state its repository**, and
   `Repositories.loadLocal()` was dropped from the startup `Future.wait` —
   each state's `load()` already restores its own repository, so calling both
   read the same storage twice.
4. **Two new test files** (`auth_flow_test`, `repository_wiring_test`) — sign-in
   had *no* test at all, which is worth knowing before touching it again.

## Decisions taken this session (with reasons)

| Decision | Reason |
|---|---|
| `register()` takes a typed `RegistrationProfile`, not a map | A `Map<String, Object?>` makes the wire keys a contract with no owner; the DTO owns that vocabulary and only the live impl converts it |
| No `passwordConfirmation` parameter anywhere in the app | The form refuses to submit unless the two entries match, so a mismatch is *unrepresentable* at the seam rather than merely unlikely — and the live path satisfies the server's `confirmed` rule by sending the one verified value twice, which fabricates nothing |
| `address_mode: 'manual'`, but the names still sent in `*_name` | The picker is a bundled sample of *names*, so the code slots have nothing truthful to hold; the name slots mean what they say, and dropping them would leave a reviewer approving an account with no address |
| The gate is persisted as the server's **wire** string, read back through the forgiving parser | What sits on disk is what the server said, and an unrecognised value renders as "unrecognised" instead of throwing at startup — where the catch would drop the whole session |
| The email-gate copy is **not** gated on `kShowDevTools` | It is only reachable when a server answered `pending_verification`, which means a code really was sent; the dev-tools split exists for the opposite case, no backend at all |
| The `AnimatedSwitcher` wrapper was **removed**, not repaired | It could not work with one shared `PageController` plus `GlobalKey` Forms; the PageView already animates page changes, so the wrapper bought a cross-fade at the price of the form being unusable |
| Live `register()` still throws `notWired(§3.2)` | The *mapping* is written and tested; the *transport* is multipart code that has never run against a server |
| The password step is its own step, not two more fields on Personal | "What's wrong?" stays answerable only while each step's errors are about that step's subject — Personal already held seven fields |
| A refused sign-in at the email gate **enters that gate**; every other refusal stays on the form | `PENDING_VERIFICATION` is not a failure to report: the credentials were accepted and a code was already sent, so `PendingApprovalException` carries the server's `verification_id` and the login screen hands it to `AuthState.beginEmailVerification()`. Without it, someone told to confirm their email had nothing to confirm *with* — the OTP screen needs a handle, and re-registering would open a second account |
| The mapping stays in `ApiClient`, the *decision* stays on the screen | The type's own doc says a screen handling `PendingApprovalException` is making a product decision; the repository's contract is to throw for a shut gate (pinned by a test), so the transition cannot live there |
| `beginEmailVerification()` goes through the same `_applyResult` path as a real registration | A session entered this way must be indistinguishable from one that never left the form — including `canConfirmEmail`, which then comes out true for the same reason |

## Decisions taken earlier (still standing)

| Decision | Reason |
|---|---|
| The mock repository owns the storage, not the state class | Two stores is two sources of truth for the same data; "the reviews I wrote" and "the reviews the data layer has" could disagree |
| Storage keys/JSON kept **identical** to what the state classes wrote | An upgrade must not lose a single order, review or thread |
| Live repositories **throw** rather than containing request code | Untested HTTP that has never run is not a feature; `ApiNotImplementedException` names the missing endpoint, and a test now pins "no silent fallback" |
| `Repositories.loadLocal()` **not** called from `main()` | Each state's `load()` already restores its repository; doing both read the same storage twice |
| `AuthState.login()` became async and takes `password` | The credential has to reach the repository — otherwise a live sign-in would be attempted with an empty one |
| The **600ms login, 700ms checkout and 500ms registration delays were deleted** | Each stood in for a call that now actually happens |
| `CourierRepository` stays the narrowest (jobs only) | Milestones/payouts/ratings are still device-simulated; moving them would rewrite a behaviour pinned by tests for no gain |
| `CartState(vouchers:)` named for what it owns | A parameter called `cart` inside `CartState` would say nothing about what is being handed over |

## Flags raised and awaiting the user

- **`routes/web.php`'s throttles share one bucket per IP.** Found by running the
  real flow: `ThrottleRequests` keys a guest by `domain|ip` with no route in the
  key, so register (5/min), verify (10/min) and resend (3/5min) are three limits
  on the *same* counter — three registration attempts spend the resend budget, and
  behind a carrier-NAT mobile connection everyone on that address shares it. The
  new API routes each got a per-route prefix (`throttle:3,5,api-otp-resend`); the
  web routes were left alone because changing them changes the web app's
  behaviour, which is your call.
- **`docs/api-contract.md` is cited 14× in `lib/` and does not exist in this
  repo** (`docs/` is absent and untracked). Narrowed, not closed: the auth
  endpoints are now specified by `web/routes/api.php`, which *is* here, and the
  document's auth section was wrong anyway (Inertia controllers, no
  `routes/api.php`, no token auth, different URL shapes). Every endpoint that does
  not exist yet still cites it.
- **Live mode still cannot boot** — for a smaller reason than before. The auth
  half works end to end now; what kills launch is the catalogue: `main()` awaits
  `OrderState.load()`, and its live repository throws
  `ApiNotImplementedException` before `runApp`. Booting with an empty cache and
  saying so visibly is the honest option, and the "no silent fallback" rule still
  forbids swallowing it.
- **The courier half has no API.** `LiveAuthRepository` names
  `POST /login/logistics` and `POST /register/logistics` rather than dressing a
  buyer request in courier words. It waits on the form decision below.
- **The courier registration form does not match the server's request.** It
  collects a vehicle type and a plate number, which
  `StoreLogisticsRegistrationRequest` has no field for, and omits two fields that
  request *requires* (`business_name`, `dti_permit_upload`). A product decision
  (change the form, or the server) is needed before any code — see
  `progress.md`. The courier cannot actually register until it is settled.
- **Fonts are still not bundled** (`techContext.md`) — the pubspec comment
  claims otherwise.
- **`test/failures/` is not gitignored** — a failing golden leaves PNGs behind.

## Next steps

1. **Whether live mode boots with an empty cache.** The auth path is real now;
   the catalogue has no endpoint, and `main()` awaits it, so a live build still
   dies before `runApp`. Either boot with empty state and a visible notice, or
   wire the catalogue endpoints next and make live mode real.
2. **The courier registration form vs `StoreLogisticsRegistrationRequest`** — a
   product decision (see the flag). It now blocks the courier's API half as well
   as the form.
3. **Whether to fix the web routes' throttles too** (flag above). One word per
   route, but it changes the web app's behaviour.
4. **Whether `AuthState` calls `refresh()` on boot** now that `GET /me` exists —
   the repository method is implemented and tested, and nothing calls it yet.
5. Tier 2's other half: sort / filter on category grids, then search history.

## Working notes / preferences

- **Run it, or it is not real.** Three things this pass were *only* visible by
  executing the flow, and all three were wrong before that: the OTP body's field
  name (settled by reading the frozen web client), the throttle bucket (a first
  resend answered `429` because four unrelated requests shared its counter — the
  web routes still have this), and the guard behaviour on a revoked token (which
  the in-process PHPUnit test *cannot* observe, because Laravel reuses the app
  inside one test method and Sanctum's request guard caches the user it resolved;
  the 401 only appeared when each request was its own process). Reading the code
  would have produced three confident, wrong sentences.
- **Prove a guard can fail.** Four families this session, each broken and then
  reverted: `kMinPasswordLength` drifted to 5 (broke the pinned constant *and*
  let a 7-character password through the form); `''` sent instead of the password
  (broke exactly the one test pinning it); the mock's code check inverted (broke
  exactly the three about accepting its own code and refusing others);
  `canConfirmEmail` weakened to ignore the missing handle (broke exactly the two
  that pin "no handle, no field"). The pattern worth keeping is *targeted*
  failure: a guard whose removal breaks ten unrelated tests is a guard nobody can
  reason about.
- **A pending-timer failure in a widget test is evidence, not noise.** The OTP
  screen's `ListView` children were unkeyed; adding the error block shifted their
  slots, Flutter re-inflated the widgets *after* it, and each fresh
  `StaggeredReveal` restarted its `Future.delayed` — surfacing as "A Timer is
  still pending" at teardown, and *also* being a real visual bug (the Confirm
  button re-faded when you retyped a digit after a refusal). Keying the children
  fixed both. Pumping a second and moving on would have left the re-fade in the
  app.
- **The live repositories throw synchronously** (they are not `async`), so
  `expectLater(live.verifyOtp(...), throwsA(...))` throws while *building* the
  argument rather than while awaiting it. Wrap the call:
  `expectLater(() async => live.verifyOtp(...), ...)`.
- **Before reporting a bug, establish whose it is.** The registration stepping
  bug was confirmed *pre-existing* by stashing this session's `lib/` changes and
  reproducing it on committed code — `git stash push -- lib/`, run the probe,
  `git stash pop`. "I broke this" and "this was already broken" are different
  claims and only one of them is worth reporting as a finding.
- **Look at the output**, and measure rather than eyeball (colours).
- Goldens are tracked and assertable: `--update-goldens`, **then open the PNGs**.
  Assert geometry and colour, never copy.
- Delete temporary harnesses (`tmp_*`) when done; keep the tree clean.
- **The tree is committed** (6 commits on `mobile-dev`, not pushed): the root
  `.gitignore`, `memory-bank/`, the mobile data layer, the password/registration
  pass, the OTP pass, and this one — the API surface. The last is the first commit
  to touch **both** sides: `web/` (Sanctum, `routes/api.php`, two API controllers,
  the buyer model's shared code policy, and `tests/Feature/Api`), `mobile/lib/`
  (`LiveAuthRepository`, `ApiClient.postMultipart`, the two model-side refactors),
  `mobile/test/live_auth_test.dart`, and the memory bank.

## Earlier decisions, still standing

- `Product.images` is an ordered photo list, cover first; `Product.photos` falls
  back to a derived placeholder and `Product.hasGallery` gates the pager chrome.
- The courier name guard lives *inside* `advanceMilestone`, so "a name before
  assignment" is structurally impossible rather than caller-dependent.
- Courier portrait shows a monogram, not a stock photo of a stranger.
- Return pays a partial trip fee (`kReturnFeeShare = 0.5`), stored per job.
- Gallery dots use `AppColors.accent` — measured 4.99 light / 5.12 dark.
- `ProductImage` takes the whole `Product` rather than a hand-passed tint.
- **The courier rating is still deliberately not shown to buyers**, even though
  `CourierState.averageRating` exists: showing it to the person at the door
  changes how the worker behaves there, and a new rider must never be shown
  "0.0". That is a product decision, not a code one — still open.