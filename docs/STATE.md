# State

Branch: feature/s1-lite  HEAD: a9489b4  Date: 2026-11-05
Stage: S1-lite DONE (1.1 docs/feature-status.md in full; 1.2 docs/spec-conformance.md High-priority B1-B14 + R1-R7). Next: REVIEW STOP 1.
Base check: 6dfa162 is an ancestor of HEAD; branch cut from integration/hardened.

## Test counts (HEAD a9489b4)
- php artisan test: 439 passed, 0 failed (2082 assertions)
- flutter test: 753 passed, 0 failed
- flutter analyze: 4 issues found (all in uncommitted mobile/lib/services/media_picker.dart; pre-existing S2.1, left untouched)

## S1-lite done
- 1.1: docs/feature-status.md rewritten — 35 mobile screens table with REACHABLE/MOCK WORKS/BACKEND/LIVE WIRED/LIVE TEST/DEVICE-CREDENTIALS/STATUS; counts per STATUS; mock-vs-live differences; proposed demo split.
- 1.2: docs/spec-conformance.md — 101 High-priority cases across B1-B14 and R1-R7 with STATUS, evidence, counts by sheet x status, CONFLICTS (shipping, vouchers, OTP-then-approval, minimum spend), MUST/SHOULD/LIMITATION scope split.

## Open
- API admin logistics approve/reject has no success test; Admin lacks HasApiTokens. Recorded for S6 route/test sweep.
- Pre-existing uncommitted S2.1 media-picker changes left untouched (source of the 4 analyze issues).
- REVIEW STOP 1: send docs/STATE.md plus docs/spec-conformance.md (summary part) and docs/feature-status.md (counts and demo split).
