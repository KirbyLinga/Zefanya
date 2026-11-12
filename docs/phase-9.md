# Phase 9 implementation checkpoints

## 9A.1 — Refund eligibility

The return service now verifies approval and recorded funds before recording an external refund. A return refund cannot use funds reserved by the cancellation refund ledger. COD requires recorded collection or a matching legacy cash receipt, and the refundable amount cannot exceed the shipment payment. The Admin refund form uses the same rules. Approved Buyer returns can still record a funded refund before physical receipt, while a non-Buyer return requires verified completion.

Verification on isolated SQLite covered Seller return decisions, physical returns, and COD financial workflows. **110 tests passed with 840 assertions.** The focused Flutter Live API order and return tests passed **35 tests**. Pint, the Web build, and the whitespace check passed.

Refund-versus-settlement concurrency remains unverified. This checkout has neither the dedicated MySQL runner credentials file nor the disposable-schema ownership marker expected by its existing preflight. No MySQL schema was changed.

## Remaining sequence

- **9A.2** — Implement the Rider-to-Logistics cash discrepancy workflow. The existing exact receipt guard is still in place. The broader 9A checkpoint requires this item and its regression tests.
- **9B–9F** — Follow the approved sequence after 9A verification.
