# Native review security proof — 2026-11-07

Scope: this newer checkout, on temporary branch `tmp/mutation`. No mutation was committed or pushed. Tests use their isolated test database; no development migration was run.

Each command uses `C:\PHP\php.exe -d extension=gd vendor/phpunit/phpunit/phpunit tests/Feature/Buyer/MobileReviewApiTest.php --filter TEST_NAME` from `web/`.

| Rule broken by hand | Test | Exact failure message |
| --- | --- | --- |
| Allow DELIVERED as well as COMPLETED in eligible item query | test_delivered_unknown_future_and_expired_completion_are_refused | Expected response status code [422] but received 201. |
| Extend completion cutoff from 30 to 3000 days | test_delivered_unknown_future_and_expired_completion_are_refused | Expected response status code [422] but received 201. |
| Bypass the existing isEditableByBuyer guard | test_completed_purchase_can_be_reviewed_then_edited_once | Expected response status code [422] but received 200. |
| Allow WebP in both native form-request and service validators | test_stars_comment_and_content_checked_photo_limits_are_enforced | Expected response status code [422] but received 201. |
| Make shipment review policy return true for everyone | test_review_policy_explicitly_allows_only_the_shipment_owner | Failed asserting that true is false. |

Every mutation returned `result: failed`, `tests: 1`, `passed: 0`, `failed: 1`. Each was restored before the next mutation.

Restored production files: ProductReviewService.php, MobileReviewRequest.php, SellerOrderPolicy.php. `git diff --exit-code` for all three returned 0.

Restored review, existing web review, shipment and public storage suites:

`{"tool":"phpunit","result":"passed","tests":37,"passed":37,"assertions":233,"duration_ms":65185}`

No real MySQL concurrent-request proof is claimed. The full backend run retains its one concurrency skip; this proof exercises guards and database state in the isolated test setup.

## S6.4 cancellation — seven independent checks

Temporary branch: `tmp/cancellation-proof`. Each mutation was applied by hand and restored before the next. Each recorded `result: failed`, one failed test, zero passed tests. No mutation was committed or pushed.

| Rule broken | Test body exercised | Exact failure |
| --- | --- | --- |
| Add READY_FOR_PICKUP → CANCELLED to the buyer actor map | OrderActorMapTest::test_buyer_cannot_cancel_after_ready_for_pickup | Failed asserting that exception of type "InvalidArgumentException" is thrown. |
| Remove preset reason validation | CancellationRulesTest::test_buy_can_002_unknown_reason_is_refused_by_web_api_and_service | Expected response status code [422] but received 200. |
| Record 1 minor unit instead of the stored paid amount | CancellationRulesTest::test_buy_can_003_paid_ewallet_records_exact_refund_liability_once | Failed asserting that 1 is identical to 108300. |
| Restore an expired voucher claim | CancellationRulesTest voucher expiry/replay test | Failed asserting that true is identical to false. |
| Do not decrement usage when it is 1 | Same voucher expiry/replay test | Failed asserting that 1 is identical to 0. |
| Remove the cancellation stock-restoration call | Rule04BuyerCancellationTest::test_buy_can_001_unaccepted_cod_cancels_for_both_roles_restores_twenty_and_notifies_with_reason | Failed asserting that 18 is identical to 20. |
| Remove the unique payment index in the isolated test migration | CancellationRulesTest::test_the_database_refuses_two_refund_liabilities_for_one_payment | Failed asserting that exception of type "Illuminate\\Database\\QueryException" is thrown. |

The voucher test ran under its original `test_buy_can_004_only_unexpired_voucher_returns_and_usage_decrements_once` name. The identical body was then renamed to `test_buy_can_003_and_006_only_unexpired_voucher_returns_and_usage_decrements_once` to match the workbook; the final Artisan run passed all eight tests.

All four changed guard files matched their pre-mutation SHA256 checksums after restoration: OrderActor.php, BuyerOrderCancellation.php, SellerOrderStatusService.php and the additive refund migration. The snapshot is ignored local verification output, not source-editing code.

Restored broader cancellation/API/voucher/account-deletion/actor suite:

`{"tool":"phpunit","result":"passed","tests":113,"passed":113,"assertions":735,"duration_ms":32224}`

Final normal Artisan runner after the case-name correction:

`{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":75,"duration_ms":6609}`

No development migration or real payment/refund transfer was run. No real MySQL race proof is claimed.

Follow-up strengthens the paid ewallet fixture with MCH50, requiring its wallet claim to return and usage to reach zero in the same successful cancellation. Final Artisan summary: `{"tool":"phpunit","result":"passed","tests":8,"passed":8,"assertions":77,"duration_ms":7727}`. No assertions removed; production guards unchanged.

## S6.2 quote/shipping checkpoint — 2026-11-07

On `tmp/mutation`, deliberately edited one guard at a time with explicit apply_patch hunks. Each row ran `vendor/phpunit/phpunit/phpunit tests/Feature/Buyer/SpecCheckoutQuoteTest.php --filter=<named test>`. Each result was `{"result":"failed","tests":1,"passed":0,"failed":1}`. Restored the exact hunk before the next mutation; never restored the entire dirty checkout.

| Deliberate fault | Named test/filter | Observed FAIL line |
| --- | --- | --- |
| Allow a foreign address in CheckoutPolicy | test_quote_requires_auth | Expected response status code [403] but received 422. |
| Disable locked expected-total comparison | test_expected_total_conflict | Expected response status code [409] but received 201. |
| Allocate platform rounding cent to largest store | test_buy_chk_001 | Failed asserting that two arrays are identical. |
| Save provisional cart items during preview | test_buy_chk_001 | Authentication last-use tracking is housekeeping, not a checkout write. |
| Disable aggregate duplicate-variant stock check | test_duplicate_lines | Expected response status code [422] but received 200. |
| Disable unsupported-route rejection | test_buy_chk_004 | Expected response status code [422] but received 200. |
| Accept malformed voucher codes | test_quote_validates | Expected response status code [422] but received 200. |
| Floor shipping weight instead of ceil | test_seed_is_idempotent | Failed asserting that 1 is identical to 2. |
| Remove preview throttle | test_duplicate_lines | Expected response status code [429] but received 422. |
| Read current reference rates instead of saved snapshot | test_expected_total_conflict | Expected response status code [201] but received 409. |

All ten faults restored. Broader restored checkout/shipping/voucher/API/inventory run: `{"tool":"phpunit","result":"passed","tests":200,"passed":199,"assertions":1461,"duration_ms":62980,"skipped":1}`. After strengthening the rate-change fixture, final restored Artisan run: `{"tool":"phpunit","result":"passed","tests":18,"passed":18,"assertions":115,"duration_ms":23870}`. This includes every mutated test/filter passing after restoration. No development database migration, seeding or MySQL concurrency proof is claimed.
