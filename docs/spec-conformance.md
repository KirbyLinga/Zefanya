# S6.4 — B11 cancellation conformance

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
