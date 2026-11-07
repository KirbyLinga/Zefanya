# Consolidation state

Target: ZefanyaWM/Zefanya; branch codex/combined-web-mobile-hardening; base 3b87074.
Original checkout retained unchanged. User authorized adaptation/test/commit/push; no merge authorized.
S8B: awaited portrait, OTP autofill, API reachability/reactive Retry, dirty-form/system Back/logout tests, scaled category/shipping layouts.
Focused S8B/default-mode tests: 25 passed (adaptation-mobile-tests.log).
Existing login/orders fix retained; 20 focused login/orders tests passed before adaptation.
Public image CORS: 6 tests, 15 assertions passed before adaptation; public uploads retained.
S10: canonical switch adapted; default mock, explicit live, and obsolete-key-ignored mode tests each passed. Recording/build/install instructions adapted in demo-script.md; APK rebuild/device/HTTPS acceptance OPEN.
S6.6: native API/service/UI adapted; trusted completion history, one edit, JPG/PNG up to five at 5 MB, multipart uploads. No new schema.
Focused PHP: 35 passed, 227 assertions. Focused mobile review/auth/catalogue: 73 passed.
Final full mobile: 03:34 +834: All tests passed! Web build: built in 10.02s.
Analysis: 7 existing findings (6 infos, 1 unused test-helper warning), all in unchanged files; no new findings.
Full backend run: 3696 tests, 3668 passed, 27 failed, 1 skipped; 24423 assertions, 1922930 ms. Started before web assets existed.
After building assets, all affected admin classes plus final review/shipment/image tests passed: 95 tests, 556 assertions, 107448 ms. All 27 failures cleared; no clean full-suite rerun is claimed.
Five native security mutations each failed as expected. All restored; affected suite: 37 passed, 233 assertions, 65185 ms. See mutation-proof.md.
PHP syntax and source encoding checked; no UTF-8 BOM. Final syntax/encoding and push verification recorded in the consolidation commit.
Formatting remains unrun: automatic approval review classified the required formatter as forbidden despite prior explicit approval.
Open: public Buyer 2 reviews, independent variant-line addressing, remote review photos/removal, atomic multi-item submission, MySQL race proof. Ambiguous variants fail closed.
Cancellation was not implemented in the source checkout and is not claimed here.
No dev migration, environment change, merge, tag or APK commit.
