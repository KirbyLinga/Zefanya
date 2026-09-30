# Active Context

## Where things stand

The **mobile app's data layer is now behind repository interfaces**, and the
mock/live decision is a single config line in `main()`:

```dart
final repositories = Repositories.forMode(useLive: kUseLiveApi, baseUrl: kApiBaseUrl);
```

`flutter analyze` is clean; **160 tests pass** (up from 141). Nothing is blocked
on the user except the `docs/api-contract.md` question below.

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

- **`docs/api-contract.md` is cited 14× in `lib/` and does not exist in this
  repo** (`docs/` is absent and untracked). The live stubs' endpoints and
  section numbers came from it. It needs to be committed, or the stubs rewritten
  against the real source. It is also **wrong about the auth endpoints**: the
  server's routes are `POST /register/buyer` and `POST /register/logistics`
  (Inertia web controllers; there is no `routes/api.php` and no Sanctum), not
  `POST /auth/{role}/register`.
- **Live mode still cannot boot**, by construction: `OrderState.load()` throws
  in live mode and `main()` awaits it. Honest, but "live" is not yet runnable.
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

1. Decide what happens to `docs/api-contract.md` (commit it, or stop citing it).
   Its auth section is now known to be wrong, so "commit it" means correcting it
   first.
2. The OTP entry screen (a 6-digit field + resend), with `verifyOtp`/`resendOtp`
   on `AuthRepository` and an opaque `verificationId` on `AuthResult` to carry the
   server's `buyer_id`. `PendingApprovalScreen` already names the action, so this
   is the screen that makes it actionable in-app.
3. The courier registration form vs `StoreLogisticsRegistrationRequest` — a
   product decision before any code (see the flag above).
4. Tier 2's other half: sort / filter on category grids.

## Working notes / preferences

- **Prove a guard can fail.** Done twice more this session:
  `kMinPasswordLength` was drifted to 5 (breaking the pinned-constant test *and*
  letting a 7-character password through the form) and `''` was sent instead of
  the password (breaking exactly the one test that pins it). Both reverted. Same
  idea as the 204px overflow test and the removed-courier-wiring proof.
- **Before reporting a bug, establish whose it is.** The registration stepping
  bug was confirmed *pre-existing* by stashing this session's `lib/` changes and
  reproducing it on committed code — `git stash push -- lib/`, run the probe,
  `git stash pop`. "I broke this" and "this was already broken" are different
  claims and only one of them is worth reporting as a finding.
- **Look at the output**, and measure rather than eyeball (colours).
- Goldens are tracked and assertable: `--update-goldens`, **then open the PNGs**.
  Assert geometry and colour, never copy.
- Delete temporary harnesses (`tmp_*`) when done; keep the tree clean.
- **The mobile tree is committed** (3 commits on `mobile-dev`, not pushed): the
  root `.gitignore`, `memory-bank/`, and the mobile data layer. This session's
  work is **uncommitted**: 4 modified files under `mobile/lib/`,
  `mobile/test/auth_flow_test.dart`, and three new files
  (`lib/data/dto/registration_payload.dart`, `test/registration_flow_test.dart`,
  `test/registration_payload_test.dart`). A commit is due.

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