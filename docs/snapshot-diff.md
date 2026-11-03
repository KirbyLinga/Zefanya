# Snapshot Diff: snapshot/pre-hardening-merge vs integration/hardened

Comparison of files that exist only on snapshot/pre-hardening-merge (deleted when merging to integration/hardened). Nothing was merged.

## Test files only on snapshot

### mobile/test/checkout_voucher_test.dart
- shows an empty picker and no savings when nothing is applied
- shows an applied voucher, its discount and removes it
- browse wallet applies the selected claimed code
- wallet refusal leaves the checkout unchanged
- Buy Now has no picker because its lines are not in the cart

### mobile/test/tracking_map_test.dart
- moves the rider forward as the milestone advances
- polls the milestone endpoint and has no coordinates yet
- draws the pickup, rider and dropoff pins
- omits the map before a job exists
- a live poll advances the milestone through the repository
- the map card survives 1.3x text without overflow

## Non-test files only on snapshot (count by folder)

| Folder      | Count | Files                                              |
|-------------|-------|----------------------------------------------------|
| mobile/lib  | 2     | data/repositories/tracking_repository.dart, widgets/tracking_map_card.dart |
| mobile/test | 1     | support/transparent_tile_provider.dart             |
| web         | 1     | app/Services/Auth/GoogleTokenVerifier.php          |
