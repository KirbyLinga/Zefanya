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
