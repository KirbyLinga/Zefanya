# Active Context

## Where things stand

The **mobile app's data layer is now behind repository interfaces**, and the
mock/live decision is a single config line in `main()`:

```dart
final repositories = Repositories.forMode(useLive: kUseLiveApi, baseUrl: kApiBaseUrl);
```

`flutter analyze` is clean; **141 tests pass** (up from 134 at the start of the
session). Nothing is blocked on the user except the `docs/api-contract.md`
question below.

What this session actually changed:

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
| The mock repository owns the storage, not the state class | Two stores is two sources of truth for the same data; "the reviews I wrote" and "the reviews the data layer has" could disagree |
| Storage keys/JSON kept **identical** to what the state classes wrote | An upgrade must not lose a single order, review or thread |
| Live repositories **throw** rather than containing request code | Untested HTTP that has never run is not a feature; `ApiNotImplementedException` names the missing endpoint, and a test now pins "no silent fallback" |
| `Repositories.loadLocal()` **not** called from `main()` | Each state's `load()` already restores its repository; doing both read the same storage twice |
| `AuthState.login()` became async and takes `password` | The credential has to reach the repository — otherwise a live sign-in would be attempted with an empty one |
| **Registration stayed local** | `register()` requires a password and the form has no password field. Passing `''` to satisfy the signature would be inventing a credential — recorded in `progress.md` instead |
| The **600ms login delay was deleted** | It stood in for the network call that now actually happens; the same reason the 700ms checkout delay went |
| `CourierRepository` stays the narrowest (jobs only) | Milestones/payouts/ratings are still device-simulated; moving them would rewrite a behaviour pinned by tests for no gain |
| `CartState(vouchers:)` named for what it owns | A parameter called `cart` inside `CartState` would say nothing about what is being handed over |

## Flags raised and awaiting the user

- **`docs/api-contract.md` is cited 15× in `lib/` and does not exist in this
  repo** (`docs/` is absent and untracked). The live stubs' endpoints and
  section numbers came from it. It needs to be committed, or the stubs rewritten
  against the real source.
- **Live mode still cannot boot**, by construction: `OrderState.load()` throws
  in live mode and `main()` awaits it. Honest, but "live" is not yet runnable.
- **Fonts are still not bundled** (`techContext.md`) — the pubspec comment
  claims otherwise.
- **`test/failures/` is not gitignored** — a failing golden leaves PNGs behind.

## Next steps

1. Decide what happens to `docs/api-contract.md` (commit it, or stop citing it).
2. Registration's password + OTP field, then `AuthRepository.register()` gets a
   caller and the pending screen can distinguish the two gates
   (`AuthResult.registrationStatus` already carries that distinction).
3. Tier 2's other half: sort / filter on category grids.

## Working notes / preferences

- **Prove a guard can fail.** Done again this session: the courier wiring was
  temporarily removed and both new courier tests failed. Same idea as the 204px
  overflow test.
- **Look at the output**, and measure rather than eyeball (colours).
- Goldens are tracked and assertable: `--update-goldens`, **then open the PNGs**.
  Assert geometry and colour, never copy.
- Delete temporary harnesses (`tmp_*`) when done; keep the tree clean.
- **Lots of work is still untracked** (`git status`): all of `lib/data/`
  (`api/`, `dto/`, `repositories/`), `lib/state/review_state.dart`,
  `lib/state/theme_state.dart`, the review/theme screens and widgets, and every
  new `test/*` file from the last two sessions — plus `memory-bank/`, `.cline/`
  and the root `flutter_*.png` screenshots. A commit is overdue.

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