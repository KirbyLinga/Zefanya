# Incremental feature status

## Combined Courier demo — 2026-11-08

| Feature | Status | Evidence | Open gate |
| --- | --- | --- | --- |
| Separate Courier login/profile in combined app | LIVE-READY (local login only) | Explicit local setup approved/applied; real MySQL login/profile/logout and role isolation checked; app/backend HTTP 200 | No assigned-trip or phone claim |
| Assigned-manifest portal, acceptance, scans and departure | NEEDS-CREDENTIALS | Native ManifestService, encrypted expiring tokens, actor audits; 9 backend tests and mobile auth/routing/widgets | Assign a real demo manifest and rehearse it; QR/Code128/camera permissions need physical-device verification |
| GPS proximity/tracking, activation/documents/duty/capacity, reassignment, Courier pay and full discrepancies | UNREACHABLE | Deferred, no corresponding Courier actions advertised | Outside limited presentation scope |

Courier login is locally verified; the full trip demo is not LIVE-READY until assignment/rehearsal passes. See demo-script.md and DECISIONS.md. Existing Buyer/Rider status below is historical and not a complete audit of this newer integration snapshot.

## S6.4 incremental feature status

This is a scoped update, not a complete inventory of the newer project.

| Feature | Backend | Mobile/web | Verification | Status |
| --- | --- | --- | --- | --- |
| Cancel COD before Ready for Pickup | Existing buyer cancellation routes and BuyerOrderCancellation / SellerOrderStatusService | Order cancel action; preset reason selector; explicit server can_cancel mapping | CancellationRulesTest, Rule04BuyerCancellationTest, BuyerOrderCancelTest, cancellation_rules_test.dart, order_lifecycle_test.dart | Implemented and automated-tested; manual Chrome acceptance not rerun |
| Stock and voucher restoration | Existing inventory ledger and shared-voucher release | Existing confirmation text; no new mock financial ledger | Rule04BuyerCancellationTest and ShopVoucherOffersTest; expiry/counter mutation proof | Backend implemented; mock order flags are not a financial ledger |
| Paid cancellation refund liability | New payment_cancellation_refunds table; exact trusted paid amount; unique payment; rollback; account-deletion block | No prepaid checkout processor or refund-disbursement UI | CancellationRulesTest and mutation proof | BACKEND-ONLY; migration deployment required |

Earlier review limitations, live HTTPS/device release gates and MySQL race proof remain open in STATE.md. Cancellation code does not make those features complete.
