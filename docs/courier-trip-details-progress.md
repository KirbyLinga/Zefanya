# Courier recorded trip details — extension 9

Implemented part of COU-TRIP-001 using existing records: batch parcel count, recorded parcel-weight sum in kg, and actual acceptance/departure/arrival times. The weight is a parcel snapshot total, not a measured vehicle load or capacity approval. Missing/nonpositive weights show Parcel weight not fully recorded; one missing parcel prevents a misleading partial sum. No catalog recalculation or persistence occurs on reads.

The API filters weight/count using exactly the existing visible parcel membership: removed unreceived items are excluded, received or issue rows remain in history. Related seller orders are eager-loaded with only ID and weight. Existing ownership/role/rate rules stay in place; no new security or money rule. No new endpoint, schema, service, dependency or configuration. Scheduled departure, capacity enforcement and pay remain OPEN. The native manifest does not store a departure schedule; native assignment creation time cannot represent reassignment time. Neither is fabricated.

Verification:

- Related PHP: `{"tool":"phpunit","result":"passed","tests":31,"passed":31,"assertions":707,"duration_ms":23701}` — delay/demo/decline feature files. One new method checks actual timestamps, record equality, complete/mixed/zero snapshot weights and removed/received membership. Initial duplicate order/seller test fixture was corrected to an independent factory order; assertions were not weakened.
- Courier mobile: `00:19 +38: All tests passed!` — all seven Courier test files, two new cases. DTO validation/backward compatibility, UTC conversion, 360x640/text scale 1.3 display and no write requests covered.
- Analysis: `8 issues found. (ran in 8.4s)` — same seven existing infos and unused-test-helper warning, no new issues. Last full-app result remains the earlier vehicle-stage 901 run; not rerun. Last broader PHP result remains prior delay-stage 210/2444, not claimed as rerun this stage.
- No web frontend/build changes, so npm build not repeated. Dart files formatted. Pint remains explicitly waived/UNRUN. No new security/money rule to mutate; prior proofs retained.

No demo trip was advanced or edited. Local HTTP verification and preview status are recorded in STATE.md. Remaining priorities: backed schedule/capacity/pay decisions, real C1/C3/C5/device rehearsal, in-transit reassignment, geofence/background GPS/push, simultaneous MySQL proof and hosted HTTPS/APK. This partial details stage does not mark C3 or all Courier requirements complete.
