# Phase 11.7 database upgrade requirements

The local MySQL `zefanya` schema has 47 migration records matching the Phase 11.5 checkout, 133 regular migration files from that checkout without a matching record, and 17 older migration records. Phase 11.6 adds two more regular migration files.

- Obtain a backup of the intended database and restore it to a disposable MySQL instance. Capture the exact schema, indexes, foreign keys, and migration ledger before rehearsing any upgrade.
- Compare the existing `reviews`, `personal_access_tokens`, and `shipping_rates` definitions against both migration histories. Verify equivalence field by field before reconciling any ledger entry. Some of the newer migrations unconditionally create these existing tables.
- Document a forward migration sequence for missing archive markers, checkout recovery, OTP-free delivery evidence, return receipts and incidents, station and transfer custody, and finance audit fields. Preserve Buyer ownership, existing evidence, and saved money snapshots during any backfill.
- Rehearse the two Phase 11.6 additions for transfer notification stamps and separate parcel-loss cases and compensation adjustments. Verify duplicate confirmations and notification retries with native MySQL. Record any pre-existing equivalent loss data before choosing an upgrade path.
- Review the pending Courier account and refund-policy migrations independently. Confirm that each feature is meant to be activated before including it in a release sequence.
- Prove the restored copy can serve current Buyer, Rider, Courier, Seller, Logistics, and Admin workflows. Reconcile inventory, COD, refunds, and balances before and after. Prepare restoration steps and obtain separate approval before changing the populated database.
