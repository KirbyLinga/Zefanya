# Incremental feature status

## Combined Courier demo — 2026-11-08

| Feature | Status | Evidence | Open gate |
| --- | --- | --- | --- |
| Separate Courier login and assigned-manifest portal in combined app | NEEDS-CREDENTIALS | LinehaulDriver/Policy/Service, /api/courier routes, LinehaulRepository/Portal; 9 backend tests and mobile transport/routing/widget coverage | This checkout lacks .env, new tables and a provisioned local Courier; real backend/device rehearsal not run |
| Acceptance, loading scans and sealed departure | NEEDS-CREDENTIALS | Native ManifestService, encrypted expiring tokens, actor audits; native receiving completes trip | QR/Code128 camera and phone permissions need real-device verification |
| GPS proximity/tracking, activation/documents/duty/capacity, reassignment, Courier pay and full discrepancies | UNREACHABLE | Deferred, no corresponding Courier actions advertised | Outside limited presentation scope |

Not LIVE-READY until approved local setup and rehearsal pass. See demo-script.md and DECISIONS.md. Existing Buyer/Rider status below is historical and not a complete audit of this newer integration snapshot.

## S6.4 incremental feature status

This is a scoped update, not a complete inventory of the newer project.

| Feature | Backend | Mobile/web | Verification | Status |
| --- | --- | --- | --- | --- |
| Cancel COD before Ready for Pickup | Existing buyer cancellation routes and BuyerOrderCancellation / SellerOrderStatusService | Order cancel action; preset reason selector; explicit server can_cancel mapping | CancellationRulesTest, Rule04BuyerCancellationTest, BuyerOrderCancelTest, cancellation_rules_test.dart, order_lifecycle_test.dart | Implemented and automated-tested; manual Chrome acceptance not rerun |
| Stock and voucher restoration | Existing inventory ledger and shared-voucher release | Existing confirmation text; no new mock financial ledger | Rule04BuyerCancellationTest and ShopVoucherOffersTest; expiry/counter mutation proof | Backend implemented; mock order flags are not a financial ledger |
| Paid cancellation refund liability | New payment_cancellation_refunds table; exact trusted paid amount; unique payment; rollback; account-deletion block | No prepaid checkout processor or refund-disbursement UI | CancellationRulesTest and mutation proof | BACKEND-ONLY; migration deployment required |

Earlier review limitations, live HTTPS/device release gates and MySQL race proof remain open in STATE.md. Cancellation code does not make those features complete.
