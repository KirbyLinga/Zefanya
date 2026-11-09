# Combined Courier security proof — 2026-11-08

## Last trip location

On tmp/mutation, removing the current-carrier check failed the takeover empty-state assertion (0 passed, 14 assertions). Removing the current-leg timestamp check failed the post-continuation empty-state assertion before a new heartbeat (0 passed, 18 assertions). Both faults were restored and the temporary branch removed. Named regression: `test_logistics_trip_location_tracks_only_the_current_leg_and_marks_old_reports_stale`.

## Van trip rate

Removed the Van rate on tmp/mutation. `test_reviewed_van_rate_is_frozen_at_departure` failed with expected quote/60000 and actual not_configured/null (0 passed, 1 assertion). Restored the rate and removed the temporary branch.

## Breakdown reassignment and takeover

Eleven separate deliberate faults on tmp/mutation, all restored before the integrated regression run. SQLite memory and fake storage only.

| Fault | Test prefix | Observed failure |
| --- | --- | --- |
| Accept one rescan | test_every_parcel | Expected 422, actual 200 after partial scan |
| Bypass arrival guard | test_every_parcel | Expected 422, actual 200 |
| Bypass receiving guard | test_every_parcel | Unrescanned takeover reached receiving |
| Ignore original dispatch actor | test_only_finishing | Expected not_configured, actual earned |
| Carry over the old pay rate | test_reassignment_preserves | Expected null, actual 100000 |
| Ignore continuation marker | test_reassignment_preserves | Expected needs_rescan true, actual false |
| Skip reassignment duty check | test_replacement_readiness | Unready replacement accepted |
| Skip reassignment capacity check | test_replacement_readiness | Overweight replacement accepted |
| Skip continuation duty check | test_continue_rechecks | Off Duty continuation accepted |
| Skip continuation capacity check | test_continue_rechecks | Overweight continuation accepted |
| Remove reassignment transaction | test_exact_reassignment_retry | Assignment changed after injected audit failure |

## Breakdown photo company access — 2026-11-09

On tmp/mutation remove only company-ID equality from LinehaulPolicy::viewBreakdown. Named test test_logistics_photo fails: expected 403, received 200 (0 passed/3 assertions/3269ms). Restore exact check; delete temporary branch and run all twelve Courier PHP files: 89 passed/1806 assertions/28104ms. No fault remains; only SQLite/fake storage used.

## Breakdown evidence backend — 2026-11-09

Four literal faults on tmp/mutation, isolated SQLite/fake storage; restore each before the next, final five tests passed/80 assertions/7011ms. Authorized Courier branch restored; temporary branch deleted. Existing assignment/phase/offer policy proofs retained through reused delay service.

| Fault | Named test prefix | Observed detection |
| --- | --- | --- |
| Store photo publicly | test_photo_is_required | failed, 0 passed/13 assertions; missing private image, getimagesizefromstring error |
| Skip re-encoding | test_photo_is_required | failed, 0 passed/14 assertions; expected image/jpeg, actual image/png |
| Remove retry photo hash guard | test_identical_retry | failed, 0 passed/4 assertions; expected 422, actual 200 |
| Skip failure cleanup | test_draft_reports | failed, 0 passed/6 assertions; expected empty files, actual leftover courier-breakdowns/*.jpg |

## Full dated terminal history — 2026-11-09

Six independent literal faults on tmp/mutation, named LinehaulHistoryTest prefix against SQLite memory, wait for completion and restore exact hunks. Authorized branch restored and temporary branch deleted before final suite. No local database change.

| Fault | Test prefix | Observed FAIL |
| --- | --- | --- |
| Remove assignment-candidate Courier filter | test_history_reaches_records | Failed asserting that 51 is identical to 0. (13 assertions, 3583ms) |
| Remove assignment-candidate company filter | test_history_company_filter | Failed asserting that 1 is identical to 0. (2 assertions, 3446ms) |
| Exclude start midnight | test_history_date_boundaries | Failed asserting that 1 is identical to 2. (2 assertions, 3459ms) |
| Include following midnight | test_history_date_boundaries | Failed asserting that 3 is identical to 2. (2 assertions, 3394ms) |
| Omit retained declined-offer candidates | test_history_includes_all_terminal | Failed asserting that 3 is identical to 4. (4 assertions, 3554ms) |
| Remove bounded date range | test_history_auth_validation | Expected response status code [422] but received 200. (10 assertions, 3418ms) |

Each failed/tests 1/passed 0. Candidate-scope faults prove counts are private even with hydration guards intact. Existing shared auth/policy/throttle proofs retained; normal new route coverage includes 401/403/422/429/success. Final restored PASS: `{"tool":"phpunit","result":"passed","tests":238,"passed":238,"assertions":2805,"duration_ms":83091}`. Mobile `00:24 +46: All tests passed!`. Actual MySQL concurrent consistency remains OPEN.

## Earnings totals and date ranges — 2026-11-09

Seven independent faults on tmp/mutation, named LinehaulEarningsTest prefix using SQLite memory, wait to finish then restore exact literal hunks. Return to authorized branch/delete temporary branch before final suite. No local migration or fabricated financial data.

| Fault | Test prefix | Observed FAIL |
| --- | --- | --- |
| Remove actual Courier filter | test_total_covers_all_pages | Failed asserting that 2100000 is identical to 0. (10 assertions, 3555ms) |
| Remove manifest company scope | test_company_scope | Failed asserting that 100000 is identical to 0. (2 assertions, 3541ms) |
| Exclude first instant of start date | test_inclusive_philippine | Failed asserting that 100000 is identical to 200000. (2 assertions, 3531ms) |
| Include following midnight | test_inclusive_philippine | Failed asserting that 300000 is identical to 200000. (2 assertions, 3135ms) |
| Count unconfigured amount as confirmed | test_inclusive_philippine | Failed asserting that 3 is identical to 2. (3 assertions, 3444ms) |
| Total only visible page | test_total_covers_all_pages | Failed asserting that 2000000 is identical to 2100000. (2 assertions, 4272ms) |
| Remove maximum date range | test_date_range_and_page | Expected response status code [422] but received 200. (4 assertions, 3990ms) |

Each failed/tests 1/passed 0. Shared authentication/throttle/policy remain unchanged with normal 401/403/429 coverage. Final restored PASS: `{"tool":"phpunit","result":"passed","tests":232,"passed":232,"assertions":2703,"duration_ms":92934}`. Mobile `00:28 +43: All tests passed!`. Real MySQL concurrent execution remains open.

## Per-trip pay foundation

Six independent literal faults on tmp/mutation; run named LinehaulPayTest prefix using SQLite memory, wait for completion and restore exact hunks. Return to authorized branch and delete tmp/mutation before final regression. No dev migration. Previous shared-auth/ownership mutation proofs retained; normal new pay privacy test covers another Courier.

| Fault | Test prefix | Observed FAIL |
| --- | --- | --- |
| Change four-wheel rate by one cent | test_supported_rates | Expected amount_minor 100000, actual 99999. (1 assertion, 3599ms) |
| Allow repricing an existing snapshot | test_supported_rates | Failed asserting that 150000 is identical to 100000. (4 assertions, 3587ms) |
| Mark earned before native handover | test_pay_is_earned_only | Expected pending, actual earned. (1 assertion, 3665ms) |
| Ignore dispatching Courier identity | test_pay_is_earned_only | Expected not_configured, actual earned. (5 assertions, 3483ms) |
| Remove cancelled/declined zero classification | test_unknown_legacy_completion | Expected ineligible/0, actual not_configured/null. (2 assertions, 3490ms) |
| Quote unreviewed/expired vehicle | test_unreviewed_or_expired | Expected not_configured, actual quote. (1 assertion, 3476ms) |

All failed/tests 1/passed 0. Final restored PASS: `{"tool":"phpunit","result":"passed","tests":227,"passed":227,"assertions":2604,"duration_ms":79324}`. Mobile `00:20 +40: All tests passed!`; analysis unchanged eight issues (9.1s). Actual MySQL concurrent requests remain unverified; test-only approved profile metadata exists only in SQLite fixtures.

## Planned departure

Five independent literal faults on tmp/mutation; run named LinehaulScheduleTest prefix against SQLite memory, wait, restore exact hunks. Return to authorized Courier branch and delete temporary branch before final regression. No development migration. Existing shared ownership/auth policy remains unchanged with new route 401/403/422/success/429 coverage.

| Fault | Test prefix | Observed FAIL |
| --- | --- | --- |
| Ignore expected schedule version | test_schedule_stale | Expected response status code [422] but received 200. (4 assertions, 3550ms) |
| Ignore forward-Draft phase | test_schedule_clear | Expected response status code [422] but received 200. (4 assertions, 3662ms) |
| Accept past planned time | test_schedule_validates | Expected response status code [422] but received 200. (1 assertion, 3390ms) |
| Remove transaction | test_schedule_audit_failure | Failed asserting that saved Carbon date is null. (2 assertions, 3593ms) |
| Reject saved immediate retry | test_schedule_stale | Expected response status code [200] but received 422. The departure schedule changed. (2 assertions, 3621ms) |

Each failed/tests 1/passed 0. Final restored PASS: `{"tool":"phpunit","result":"passed","tests":220,"passed":220,"assertions":2573,"duration_ms":72644}`. Mobile `00:17 +38: All tests passed!`; analysis eight unchanged issues (11.9s); web build 6.91s before PHP. MySQL concurrent execution remains OPEN.

## Reviewed vehicle capacity

On tmp/mutation, apply each literal fault independently, run LinehaulDelayTest named prefix in SQLite memory, wait for completion and exactly restore. All five detected. Return to codex/combined-courier-demo and delete temporary branch before final regression. No whole-tree restore or local migration.

| Fault | Test prefix | Observed FAIL |
| --- | --- | --- |
| Remove new-assignment capacity check | test_capacity_blocks_assignment | Invalid trip capacity was accepted. (1 assertion, 3614ms) |
| Remove acceptance recheck | test_capacity_rechecks_changed_weight_before_acceptance | Invalid trip capacity was accepted. (1 assertion, 3494ms) |
| Remove departure recheck | test_capacity_rechecks_changed_weight_before_departure | Invalid trip capacity was accepted. (1 assertion, 3479ms) |
| Treat missing/nonpositive weights as valid | test_capacity_blocks_assignment | Invalid trip capacity was accepted. (1 assertion, 3387ms) |
| Permit one extra gram | test_capacity_blocks_assignment | Invalid trip capacity was accepted. (5 assertions, 3486ms) |

Each was failed/tests 1/passed 0. Final restored PASS: `{"tool":"phpunit","result":"passed","tests":214,"passed":214,"assertions":2491,"duration_ms":56624}`. Existing no-profile compatibility is deliberate and tested by the unchanged Courier suite; it remains a documented scope gap. Concurrent MySQL proof is OPEN.

## Delay reporting

Ten faults individually applied with literal hunks on tmp/mutation, tested using SQLite memory, then exactly restored. The temporary branch was deleted after returning to codex/combined-courier-demo. No whole-tree restore or development migration. Test prefixes below refer to LinehaulDelayTest; each result was failed, tests 1/passed 0. Transaction fault was repeated once to recapture truncated output.

| Fault | Test prefix | Observed FAIL |
| --- | --- | --- |
| canReport always true | test_new_reports_only | Expected response status code [422] but received 200. |
| Accept past ETA | test_report_requires_real_owner | Expected response status code [422] but received 200. |
| Omit UTC normalization | test_duplicate_offsets | Failed asserting that 2 is identical to 1. |
| Ignore expected offer version | test_report_requires_real_owner | Expected response status code [422] but received 200. |
| Randomize fingerprint | test_duplicate_offsets | Failed asserting that 2 is identical to 1. |
| Omit latest report driver/provider/epoch binding | test_delay_metadata_is_bound | Expected null; received metadata array. |
| Remove transaction | test_report_audit_failure | Failed asserting that 1 is identical to 0. (assertions 2, 3753ms) |
| Disable model/common ORM history guards | test_report_audit_failure | Delay history was changed. (assertions 5, 3701ms) |
| Remove reason maximum | test_report_requires_real_owner | Expected response status code [422] but received 200. (assertions 5, 3698ms) |
| Check future eligibility before saved retry lookup | test_duplicate_offsets | Expected response status code [200] but received 422. The new ETA must be in the future. (assertions 9, 3886ms) |

Shared real-role authentication, ownership policy and throttle remain unchanged, with new route 401/403/429 tests and earlier shared-layer mutation proofs retained. Final restored PASS: `{"tool":"phpunit","result":"passed","tests":210,"passed":210,"assertions":2444,"duration_ms":58286}`. Mobile: `00:19 +36: All tests passed!`; analysis unchanged eight existing issues; web build 6.41s. Concurrent MySQL execution is OPEN; SQLite checks are not evidence of production lock scheduling.

## Trip decline and retained offers

On tmp/mutation, apply each fault separately with literal apply_patch hunks, run the named LinehaulDeclineTest method against SQLite memory, wait for completion, and restore the exact hunk. Preserve pending stage work rather than restoring the entire dirty tree. All ten faults detected; return to codex/combined-courier-demo and delete tmp/mutation before the final suite. No local dev migration or demo action used for these checks.

| Fault | Test prefix | Observed FAIL |
| --- | --- | --- |
| Decline policy returns true | test_decline_requires_real_owner | Failed asserting true is false |
| Bypass eligibility for accepted/loaded/non-Draft offers | test_accept_or_loading | Expected 422, received 200 |
| Disable required-reason validation and nonblank guard | test_decline_requires_real_owner | Expected 422, received 200 |
| Ignore expected offer version | test_decline_requires_real_owner | Expected 422, received 200 |
| Remove shared current-offer filter | test_decline_unassigns | Failed asserting true is false (still busy) |
| Ignore changed reason/version on replay | test_decline_unassigns | Expected 422, received 200 |
| Reoffer the same declined batch to the original Courier | test_reassignment_has_one_new_owner | Declined Courier was reoffered the trip |
| Read current parcel count instead of original snapshot | test_reassignment_has_one_new_owner | Failed asserting 2 is identical to 1 |
| Disable history model/common ORM edit/delete guards | test_history_survives_native | Immutable history was changed |
| Remove decline transaction boundary | test_audit_failure_rolls_back | Failed asserting 1 is identical to 0 (partial history persisted) |

Final restored PASS: `{"tool":"phpunit","result":"passed","tests":203,"passed":203,"assertions":2281,"duration_ms":55297}`. Focused mobile `00:18 +31: All tests passed!`. Analysis `8 issues found. (ran in 10.4s)` (same baseline). Web build 6.60s.

The new route shares unchanged Sanctum/real-role/60-per-minute middleware with the existing Courier group; new feature tests cover 401/403/ownership/422/success/429 and prior shared-middleware proofs remain. Both serialized accept-vs-decline outcomes and stale replays after reassignment are tested, not real simultaneous MySQL requests. MySQL concurrency proof remains OPEN. No earnings/pay rule added or claimed.

## Hub directions URL safety

On tmp/mutation, apply each fault independently to DeviceCourierDirections.destinationUri and run `linehaul_navigation_test.dart --plain-name 'directions encode hub text'`. Restore the exact hunk afterward, return to codex/combined-courier-demo, delete the temporary branch.

| Fault | Observed FAIL |
| --- | --- |
| Change fixed Maps host to example.test | Expected 'www.google.com', Actual 'example.test' |
| Interpolate raw address into query instead of Uri.https encoding | Expected destination containing `&`, `#`, Unicode and a nested URL; actual truncated destination plus injected map key, missing travelmode |

Final restored PASS: `00:13 +26: All tests passed!`. Related PHP PASS: `{"tool":"phpunit","result":"passed","tests":39,"passed":39,"assertions":994,"duration_ms":29054}`. Analysis `8 issues found. (ran in 9.4s)` (same existing baseline). No new backend authorization/money rule, route or migration; existing own-driver/company filtering is retained and tested for the added hub fields. This tests URL construction and injected launcher behavior, not an actual device's Maps app.

## Vehicle records and private documents

On tmp/mutation, apply one deliberate fault at a time with literal apply_patch hunks, run the named LinehaulVehicleTest method against SQLite memory, and restore the exact hunk. Preserve all pending stage work; do not restore the entire working tree. All 18 faults below were detected. Return to codex/combined-courier-demo and delete tmp/mutation before the final clean run. No local MySQL schema/data was used for these faults.

| Fault | Test method prefix | Observed FAIL |
| --- | --- | --- |
| Company review policy returns true | test_other_company | Expected 403, received 200 |
| Remove license expiry submission boundary | test_expiry_capacity | Expected 422, received 200 |
| Remove 5 MB maximum | test_expiry_capacity | Expected 422, received 200 |
| Disable MIME plus image-content validation | test_expiry_capacity | Expected 422, received 200 for renamed executable |
| Disable shared plate collision refusal | test_duplicate_plates | Expected 422, received 200 |
| Accept prohibited identity/review fields | test_vehicle_routes_require | Expected 422, received 200 |
| Permit submissions while working | test_off_duty_active | Expected 422, received 200 |
| Ignore submitted version on save | test_off_duty_active | Expected 422, received 200 |
| Ignore submitted version on review | test_off_duty_active | Expected 422, received 302 |
| Allow On Duty with unapproved submitted records | test_submission_private | Expected 422, received 200 |
| Download first profile instead of authenticated profile | test_submission_private | Expected 404, received 200 |
| Skip new-file cleanup after failed submission | test_missing_file_or_failed | Expected empty array, actual two orphaned files |
| Permit overwriting an already reviewed version | test_submission_private | Expected 422, received 302 |
| Remove named company route throttle | test_vehicle_and_company_routes_are_throttled | Expected 429, received 422 |
| Approve a missing physical file | test_missing_file_or_failed | Expected 422, received 302 |
| Ignore expiry in readiness after fresh location | test_approved_license_expiry | Failed asserting true is identical to false |
| Remove capacity bounds/precision | test_expiry_capacity | Expected 422, received 200 |
| Allow incomplete initial document set | test_missing_file_or_failed | Expected 422, received 200 |

Restored focused PASS: `{"tool":"phpunit","result":"passed","tests":10,"passed":10,"assertions":279,"duration_ms":9829}`.
Final restored related PASS: `{"tool":"phpunit","result":"passed","tests":193,"passed":193,"assertions":2081,"duration_ms":63407}`.
Full mobile PASS: `03:29 +901: All tests passed!`; final focused after test-only style corrections: `00:11 +19: All tests passed!`; analysis `8 issues found. (ran in 9.0s)` (existing baseline).

The global mutex/current locking reads are implemented for both application Rider creation paths and Courier profile submissions. SQLite does not prove concurrent MySQL behavior; that operational proof remains OPEN. Authenticated PDF download is tested on PHP; actual camera/Files/PDF open-share-save on a phone and hosted PHP upload limits remain OPEN. After owner-approved local activation, a real non-saving HTTP probe proved three simultaneous 5 MB image parts pass file validation and oversized parts fail; deliberately missing required expiry guaranteed no submission/private document was saved. Profile rows remain zero and all demo batches unchanged.

## Bulk receiving closeout extension

On tmp/mutation, seven faults were applied one at a time and tested against SQLite, then restored exactly. Branch deleted before the final asset-ordered related suite passed: 67 tests / 979 assertions.

| Deliberate fault | Named test prefix | Observed failure |
| --- | --- | --- |
| Allow every company in closeout policy | test_only_owning_logistics | Failed asserting that true is false (direct policy check). |
| Accept an unchecked bulk confirmation | test_confirmation_note_and_exact_snapshot | Expected response status code [422] but received 200. |
| Ignore exact reviewed pending snapshot | test_confirmation_note_and_exact_snapshot | Expected response status code [422] but received 200. |
| Skip reported arrival | test_close_requires_reported_arrival | Expected response status code [422] but received 200. |
| Remove outer closeout transaction | test_mid_batch_failure_rolls_back | Expected null released_at; first parcel remained released after simulated second-parcel failure. |
| Remove closeout throttle | test_close_route_is_throttled | Expected response status code [429] but received 422. |
| Share one throttle bucket across companies | test_close_route_is_throttled | Expected response status code [403] but received 429. |

Each fault reported tests 1, passed 0, failed 1. No broken guard was committed.

## Duty/checklist extension

On tmp/mutation, each fault below was applied alone using apply_patch, tested only against SQLite, and restored exactly. Temporary branch deleted before the final 60-test/819-assertion related suite passed.

| Deliberate fault | Named test prefix | Observed failure |
| --- | --- | --- |
| Skip assignment readiness | test_assignment_requires_fresh_readiness | Expected response status code [422] but received 302. |
| Skip active-trip Off Duty restriction | test_assignment_requires_fresh_readiness | Expected response status code [422] but received 200. |
| Remove five-minute expiry | test_assignment_requires_fresh_readiness | Expected Location unknown; actual On Duty. |
| Make checklist answers optional | test_checklist_requires_every_answer | Expected response status code [422] but received 200. |
| Remove maximum location accuracy | test_checklist_requires_every_answer | Expected response status code [422] but received 200. |
| Allow a failed item without note | test_checklist_requires_every_answer | Expected response status code [422] but received 200. |
| Treat a failed checklist as passed | test_checklist_requires_every_answer | Failed asserting that true is identical to false. |

Every mutation reported result failed, tests 1, passed 0; no broken guard was committed.

## Missing/damaged handover extension

On tmp/mutation, each deliberate fault was applied alone, tested with SQLite, restored with exact apply_patch hunks; branch deleted before the final related suite (55 passed, 687 assertions).

| Deliberate fault | Named test | Observed result |
| --- | --- | --- |
| Skip missing confirmation | test_missing_parcel_needs_confirmation | tests 1, passed 0; Expected response status code [422] but received 302. |
| Replace private-photo operational reporting with no-photo blocker | test_damaged_parcel_requires_private_photo | tests 1, passed 0; Expected response status code [422] but received 302. |
| Bypass immutable Courier-note check | test_missing_parcel_needs_confirmation | tests 1, passed 0; Expected response status code [422] but received 200. |

## Seal receiving extension

On tmp/mutation, with SQLite only, `test_seal_mismatch_requires_note_before_receipt_and_is_audited_as_an_issue` detected both deliberate faults below. Exact hunks restored, temporary branch deleted, final Courier suite passed (11 tests / 232 assertions before the final once-per-manifest assertions).

| Deliberate fault | Observed result |
| --- | --- |
| Skip required mismatch note | result failed, tests 1, passed 0: Expected response status code [422] but received 302. |
| Return before enforcing Courier receipt seal | result failed, tests 1, passed 0: Expected response status code [422] but received 302. |

Temporary branch tmp/mutation; one deliberate fault at a time, restored with exact apply_patch hunks before the next fault. Nothing was committed with a broken guard. Each command used `vendor/phpunit/phpunit/phpunit tests/Feature/LinehaulDemoTest.php --filter=<filter>`. All ten final runs reported result failed, tests 1, passed 0, failed 1.

| Deliberate fault | Filter | Observed failure |
| --- | --- | --- |
| Remove assignment ownership from policy | test_only_assigned | Expected response status code [403] but received 200. |
| Bypass active company/hub eligibility | test_login_requires | Expected response status code [403] but received 200. |
| Accept any model carrying linehaul ability | test_buyer_and_courier | Expected response status code [403] but received 200. |
| Permit a second active trip | test_one_active_trip | Session is missing expected key [errors]. |
| Permit loading before acceptance | test_live_trip_requires | Expected response status code [422] but received 200. |
| Remove seal format constraint | test_live_trip_requires | Failed to find a validation error in the response for key: 'seal_number' |
| Store scan tokens as plaintext JSON | test_expired_scans | Raw JSON contained the exact token; assertStringNotContainsString failed. Synthetic random test token omitted here. |
| Remove Courier endpoint throttle | test_courier_endpoints | Expected response status code [429] but received 200. |
| Omit Courier acceptance audit | test_live_trip_requires | Failed asserting that 0 is identical to 1. |
| Bypass local/testing provisioning restriction | test_demo_provisioning_is_refused | Output "Courier demo provisioning is available only in local/testing environments." was not printed. |

The first production-guard fault reached the lazy memory DB's production confirmation prompt. Strengthened the fixture to initialize the test DB before simulating production and repeated the fault: the final run above fails on the expected missing refusal message, not fixture setup. All prior no-write assertions retained.

Restored broader run: `{"tool":"phpunit","result":"passed","tests":121,"passed":121,"assertions":1009,"duration_ms":32119}`. Final Artisan after strengthening that fixture: `{"tool":"phpunit","result":"passed","tests":9,"passed":9,"assertions":154,"duration_ms":5781}`. Every named filter passes after restoration. No real MySQL race, local development migration or device-camera verification is claimed.

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
