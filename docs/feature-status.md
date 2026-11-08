# Incremental feature status

## Combined Courier demo — 2026-11-08

| Feature | Status | Evidence | Open gate |
| --- | --- | --- | --- |
| Separate Courier login/profile in combined app | LIVE-READY locally | Real browser login/account checked, role isolation tested | HTTPS/physical-device release not verified |
| Assigned-manifest portal, acceptance, scans, departure and Logistics receipt | Core rehearsal passed; duty gate enabled locally | Full manual-entry browser rehearsal batch #1 Completed, received count verified; user reports camera works | Complete checklist before new assignment/accept/depart; independent device coverage open |
| Arrival seal confirmation and documented mismatch | Enabled locally; end-to-end rehearsal pending | 11 Courier tests / 235 assertions; related native checks 53 tests / 599 assertions before final 3 assertions; assets built 5.90s; two deliberate bypasses detected; approved migration ran alone, receiving page loads | Real seal-extension trip rehearsal remains open; existing demo batches not advanced |
| Missing/damaged receiving and Courier handover notes | Implemented/tested locally | 55 related backend tests/687 assertions; explicit missing confirmation, private validated damage photo, native incidents/notifications, retained history, immutable own note; 887 full mobile / 12 final focused passed; analysis at prior 8 findings | End-to-end real incident/photo rehearsal open; bulk closeout implemented below |
| Duty/checklist and foreground location freshness | Enabled locally after explicit approval | 60 related backend tests/819 assertions; 890 full mobile passed; 15 final focused passed; seven bypasses caught; web build 30.24s; existing 8 analysis findings; actual API Off Duty/setup complete, history empty | Physical permission/checklist/force-kill rehearsal remains open; no background tracking/geofence claim |
| Bulk missing-parcel receiving closeout | Implemented/tested; no migration needed | 67 related backend tests/979 assertions after completed build; web build 14.43s; seven faults detected/restored; existing receipts/notes, strict review snapshot, seal/arrival and atomic rollback verified | Actual local handover/closeout rehearsal open; demo batches preserved. Mobile source unchanged |
| GPS hub proximity/background tracking, activation/documents/vehicle/capacity, reassignment and Courier pay | UNREACHABLE | Deferred, no corresponding Courier actions advertised | Later extension stages |

The local core transfer rehearsal passed. User authorized expanding Courier and approved the seal schema exception; seal confirmation is enabled locally. Remaining Courier areas are not implemented by this stage. See demo-script.md and DECISIONS.md. Existing Buyer/Rider status below is historical and not a complete audit of this newer integration snapshot.

## S6.4 incremental feature status

This is a scoped update, not a complete inventory of the newer project.

| Feature | Backend | Mobile/web | Verification | Status |
| --- | --- | --- | --- | --- |
| Cancel COD before Ready for Pickup | Existing buyer cancellation routes and BuyerOrderCancellation / SellerOrderStatusService | Order cancel action; preset reason selector; explicit server can_cancel mapping | CancellationRulesTest, Rule04BuyerCancellationTest, BuyerOrderCancelTest, cancellation_rules_test.dart, order_lifecycle_test.dart | Implemented and automated-tested; manual Chrome acceptance not rerun |
| Stock and voucher restoration | Existing inventory ledger and shared-voucher release | Existing confirmation text; no new mock financial ledger | Rule04BuyerCancellationTest and ShopVoucherOffersTest; expiry/counter mutation proof | Backend implemented; mock order flags are not a financial ledger |
| Paid cancellation refund liability | New payment_cancellation_refunds table; exact trusted paid amount; unique payment; rollback; account-deletion block | No prepaid checkout processor or refund-disbursement UI | CancellationRulesTest and mutation proof | BACKEND-ONLY; migration deployment required |

Earlier review limitations, live HTTPS/device release gates and MySQL race proof remain open in STATE.md. Cancellation code does not make those features complete.
