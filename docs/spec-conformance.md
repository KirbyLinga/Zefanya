# S6.4 — B11 cancellation conformance

## Courier extension 4 — C6 bulk closeout

COU-ARR-003: Close Receiving now lists the exact unreceived parcels, requires a checked review confirmation and Logistics explanation, records native missing incidents/recipient notifications and closes Received with Issues while retaining existing receipts and Courier notes. Stale lists, missing arrival and unexplained seal differences refuse without changes; simulated second-parcel failure rolls back the complete closeout. Seven feature tests cover these paths. Existing Courier-note entry remains available after closing. The native incident/hold is used rather than introducing a canonical Missing in Transit order enum; real local/device rehearsal remains open. Pay is still deferred.

COU-ARR-006: owning active Logistics only. The mobile Courier bearer request is refused as unauthenticated to the web guard (401 in feature testing), not granted receiving access; exact spec 403 for that cross-auth request is not claimed. Foreign Logistics is 403. Existing web CSRF also applies.

## Courier extension 3 — C2 duty scope

COU-SHIFT-001/002/003/004/005: checklist pass/fail/completeness, note + Logistics database notification, active-trip Off Duty block and persisted Off Duty/assignment exclusion are implemented and tested. Logistics readiness is on existing batch details, not a new team-roster screen. COU-SHIFT-006: explicit foreground permission request, actionable failure/settings path and no crash covered with an injected location source; physical Android/iOS permission acceptance remains open. COU-SHIFT-007: server availability expires at five minutes without a heartbeat, and foreground pause/resume is widget-tested. Device force-kill/browser geolocation rehearsal remains open. No continuous background tracking, hub geofence or anti-spoofing claim.

## Courier extension 2 — scoped C6 checks

COU-ARR-003 individual controls remain implemented/tested; bulk review/closeout was added in extension 4 above. Native incident label/hold is used; no new canonical Missing in Transit order enum is introduced.

COU-ARR-004: Courier damage receipt refuses missing/spoofed/oversized photos, stores valid images privately in the native incident, shows Damaged and Received with Issues. Tests: LinehaulDemoTest::test_damaged_parcel_requires_private_photo_and_cannot_be_reported_by_another_provider. Hardware photo upload rehearsal remains open. COU-ARR-006 boundary retained: only authenticated owning Logistics receives; the Courier note action cannot receive or close the trip.

Source: original work order's S6.4/OVERRIDES and `B11 Cancellation.csv`. Accepted/packing orders remain cancellable until Ready for Pickup.

| Case | Evidence | Result/limit |
| --- | --- | --- |
| BUY-CAN-001 | Rule04 full-state matrix: CONFIRMED/PREPARING now succeed; stock 18 to 20, notification with reason; API state matrix | Automated pass |
| BUY-CAN-002 | Existing missing/whitespace checks; new web/API/direct-service preset validation; Flutter state/confirmation tests | Automated pass |
| BUY-CAN-003 | Paid ewallet fixture with 108300 minor units; exact one pending refund; unexpired voucher release; failure rollback; pending refund prevents account deletion | Backend foundation tested. Deployment migration pending; no prepaid checkout processor or money transfer claim |
| BUY-CAN-004 | Existing OrderActorMap rejection and complete web status matrix; API can_cancel false at READY_FOR_PICKUP; Flutter mapping/copy/persistence | Automated pass |
| BUY-CAN-005 | Web matrix/API/mapping reject pickup and later statuses | Automated pass |
| BUY-CAN-006 | Existing replay/stock/notification tests; new paid-refund replay and database uniqueness test; voucher counter replay | Automated pass; real MySQL concurrent-request proof remains open |

The mock repository persists cancellation state/reason/eligibility; it is not a payment or inventory accounting simulator. Historical order lines whose variant identity is missing still need reconciliation under the existing inventory service. No test is removed or weakened; intended boundary changes and fixture corrections are recorded in DECISIONS.md.
