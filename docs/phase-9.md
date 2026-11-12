# Phase 9 implementation checkpoints

## 9A.1 — Refund eligibility

The return service now verifies approval and recorded funds before recording an external refund. A return refund cannot use funds reserved by the cancellation refund ledger. COD requires recorded collection or a matching legacy cash receipt, and the refundable amount cannot exceed the shipment payment. The Admin refund form uses the same rules. Approved Buyer returns can still record a funded refund before physical receipt, while a non-Buyer return requires verified completion.

Verification on isolated SQLite covered Seller return decisions, physical returns, and COD financial workflows. **110 tests passed with 840 assertions.** The focused Flutter Live API order and return tests passed **35 tests**. Pint, the Web build, and the whitespace check passed.

Refund-versus-settlement concurrency remains unverified. This checkout has neither the dedicated MySQL runner credentials file nor the disposable-schema ownership marker expected by its existing preflight. No MySQL schema was changed.

## 9A.2 — Rider cash discrepancy

Logistics can record a short, zero, or excess physical count against an existing COD obligation. The original count, staff name, reason, reference, and timestamp are kept with the remittance. The provider is the authenticated audit actor because the Logistics guard does not identify individual staff. The Rider can submit an explanation through Web My Money. Finance Admin can keep the case open or authorize verified recovery. A late Rider explanation reopens review. Only an exact physical receipt with a recovery reference can settle the obligation. The original case and review audit stay available.

The migration adds one nullable JSON field to the existing uniquely keyed COD remittance. No automatic deduction, payout, write-off, or money transfer is performed. Existing Flutter COD balances continue to report the full outstanding obligation. Case explanation and review are available through Web.

Verification included 123 passing Laravel tests across COD, returns, settlement, and Rider cash, and 82 passing focused Flutter Live API tests. MySQL concurrent staff acknowledgement, review versus recovery, and refund versus settlement still require the isolated MySQL runner.

## Remaining sequence

- **9B–9F** — Non-receipt disputes, post-completion defect claims, worker custody recovery, hub delay escalation, and final hardening.
