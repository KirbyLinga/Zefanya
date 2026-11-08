# Courier trip decline

## Behavior

An assigned Courier can decline only before accepting/loading/departure. The server supplies can_decline; old/missing schema never advertises the action. The branded dialog requires a reason, offers Vehicle problem / Schedule conflict / Other, and sends the exact offer version. Keep trip/dismiss makes no request. Server refusal retains the trip and shows its actual validation message.

A successful decline creates an immutable snapshot of that offer's route, saved hub addresses, parcel count, reason and date. It closes the current Courier offer, frees that Courier for another batch, and notifies only the owning Logistics database inbox. Native batch status, parcels/custody, stock, orders, payment and receipts are not changed. Declined snapshots appear in Courier History without current parcel tracking, trip controls or an invented pay amount.

Logistics sees Courier unassigned and the reason in its outgoing list/details. The list now includes all of the company's origin hubs, fixing its prior first-hub-only filter. A different ready company Courier can be assigned through the existing form. The one unique assignment row is reused with a higher version; prior driver/version history remains. Prior declining Couriers cannot be reoffered the same batch. Same-version/same-reason retry returns only the original snapshot, even after reassignment; changed requests are refused. Old Courier accept/scan/depart/arrival/note actions remain forbidden.

History survives native deletion of an unassigned Draft batch: the snapshot intentionally does not cascade with the manifest. Common ORM/model history update/delete is refused, and assignment/history/audit/database notification are transactional. This is not in-transit breakdown reassignment, manual removal of accepted offers, pay/earnings, push, GPS arrival proof or completion of all C3/C7 cases.

## Verification

- Final PHP: 203 tests / 2,281 assertions passed across Courier, Logistics and Rider registration; nine new decline tests include both serialized race outcomes, reason/version/privacy, replay, reassignment, another trip, native draft deletion, immutability, rollback, throttle, multi-hub list and company scope.
- Mobile: `00:18 +31: All tests passed!` across Courier modules, including five new decline tests at 360x640 / 1.3 text. Dialog lifetime bug fixed before completion; existing tests/assertions unchanged.
- Analysis: same eight existing issues. Web build 6.60s, performed before asset-dependent PHP tests. Pint waived/unrun.
- Ten deliberate security faults detected/restored; tmp/mutation deleted. Real concurrent MySQL requests remain OPEN.
- Existing local demo not mutated: two original assignments and batches #1 RECEIVED/#2–4 DRAFT retained; current #2 is already accepted/Loading and cannot decline. Live GET before migration gracefully returns can_decline false / offer_version 1. Own test token revoked.
- Preview port 5176 recompiled (1033ms). No new dependencies. No real offer declined or fake history created.

## Local activation — pending explicit owner exception

Run only `2026_11_08_220000_add_linehaul_trip_declines.php` after the owner's explicit permission. It adds offer_version (default 1) and nullable declined_at to existing assignment rows, plus linehaul_trip_declines. Existing assignment driver/acceptance/arrival/status/receipt values remain unchanged. Do not run other pending migrations. Verify both columns, an empty history table, unchanged assignment snapshots and batch statuses, and normal live trip GET. No need to modify .env, PHP settings or any account/document/GPS data.

Actual decline/reassignment rehearsal needs an unaccepted offer and another ready company Courier. Do not discard the current accepted demo trip, generate false decline history or provision another account without appropriate authorization just to expose the button. The current Loading offer correctly remains non-declinable after activation.

Next: remaining backed trip details and delay reporting; pay/rate setup, push, geofence/background GPS and in-transit reassignment remain separate stages. C1 real-document rehearsal still needs the user's actual documents after completing the active trip and going Off Duty.
