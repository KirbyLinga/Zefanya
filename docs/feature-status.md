# Incremental feature status

## Combined Courier demo — 2026-11-08

| Feature | Status | Evidence | Open gate |
| --- | --- | --- | --- |
| Separate Courier login/profile in combined app | LIVE-READY locally | Real browser login/account checked, role isolation tested | HTTPS/physical-device release not verified |
| Assigned-manifest portal, acceptance, scans, departure and Logistics receipt | LIVE-READY locally before seal extension activation | Full manual-entry browser rehearsal batch #1 Completed, received count verified; user reports camera works | Independent QR/Code128/permission coverage still open |
| Arrival seal confirmation and documented mismatch | Enabled locally; end-to-end rehearsal pending | 11 Courier tests / 235 assertions; related native checks 53 tests / 599 assertions before final 3 assertions; assets built 5.90s; two deliberate bypasses detected; approved migration ran alone, receiving page loads | Real seal-extension trip rehearsal remains open; existing demo batches not advanced |
| GPS proximity/tracking, activation/documents/duty/capacity, reassignment, Courier pay and full discrepancies | UNREACHABLE | Deferred, no corresponding Courier actions advertised | Outside limited presentation scope |

The local core transfer rehearsal passed. User authorized expanding Courier and approved the seal schema exception; seal confirmation is enabled locally. Remaining Courier areas are not implemented by this stage. See demo-script.md and DECISIONS.md. Existing Buyer/Rider status below is historical and not a complete audit of this newer integration snapshot.

## S6.4 incremental feature status

This is a scoped update, not a complete inventory of the newer project.

| Feature | Backend | Mobile/web | Verification | Status |
| --- | --- | --- | --- | --- |
| Cancel COD before Ready for Pickup | Existing buyer cancellation routes and BuyerOrderCancellation / SellerOrderStatusService | Order cancel action; preset reason selector; explicit server can_cancel mapping | CancellationRulesTest, Rule04BuyerCancellationTest, BuyerOrderCancelTest, cancellation_rules_test.dart, order_lifecycle_test.dart | Implemented and automated-tested; manual Chrome acceptance not rerun |
| Stock and voucher restoration | Existing inventory ledger and shared-voucher release | Existing confirmation text; no new mock financial ledger | Rule04BuyerCancellationTest and ShopVoucherOffersTest; expiry/counter mutation proof | Backend implemented; mock order flags are not a financial ledger |
| Paid cancellation refund liability | New payment_cancellation_refunds table; exact trusted paid amount; unique payment; rollback; account-deletion block | No prepaid checkout processor or refund-disbursement UI | CancellationRulesTest and mutation proof | BACKEND-ONLY; migration deployment required |

Earlier review limitations, live HTTPS/device release gates and MySQL race proof remain open in STATE.md. Cancellation code does not make those features complete.
